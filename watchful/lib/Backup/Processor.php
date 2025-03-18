<?php

namespace Watchful\Backup;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Watchful\Helpers\Logger;
use WP_Error;
use WP_Filesystem_Direct;

final class Processor
{
    private const BACKUP_PATHS_TO_IGNORE = ['wp-content/ai1wm-backups', 'wp-content/backups'];

    /** @var StateManager */
    private $stateManager;

    /** @var Utils */
    private $utils;

    /** @var ChunkedUploader */
    private $chunkedUploader;

    /** @var Logger */
    private $logger;

    public function __construct()
    {
        $this->stateManager = new StateManager();
        $this->utils = new Utils();
        $this->chunkedUploader = new ChunkedUploader();
        $this->logger = new Logger('backup');
    }

    /**
     * @throws RuntimeException
     */
    public function step_backup(array $params)
    {
        $this->logger->info('Starting backup step', ['params' => $params]);

        if (empty($params['id'])) {
            $this->logger->error('Backup ID is missing', ['id' => $params]);

            return new WP_Error(
                'backup_id_missing',
                'Backup ID is missing',
                ['status' => 400]
            );
        }


        $backup_id = $params['id'];

        try {
            $backup_state = $this->stateManager->get_state($backup_id);
        } catch (Exception $e) {
            $this->logger->error('Backup not found', ['id' => $backup_id]);

            return new WP_Error(
                'backup_not_found',
                ['status' => 404]
            );
        }

        if ($backup_state['status'] === 'completed' || $backup_state['status'] === 'failed') {
            $this->logger->info('Backup completed', ['id' => $backup_id]);

            return $backup_state;
        }

        $this->logger->debug('Backup state', ['state' => $backup_state]);

        try {
            if (!$backup_state['steps']['file_enumeration']['completed']) {
                $this->logger->info('Enumerating files', ['id' => $backup_id]);
                $this->backup_enumerate_files_chunk($backup_id, $backup_state);
            } elseif (!$backup_state['steps']['database']['completed']) {
                $this->logger->info('Backing up database', ['id' => $backup_id]);
                $this->backup_database_chunk($backup_id, $backup_state);
            } elseif (!$backup_state['steps']['archive']['completed']) {
                $this->logger->info('Adding files to archive', ['id' => $backup_id]);
                $this->backup_add_files_to_archive($backup_id, $backup_state);
            } elseif (!$backup_state['steps']['upload']['completed']) {
                $this->logger->info('Uploading backup', ['id' => $backup_id]);
                $this->backup_upload_chunk($backup_id, $params, $backup_state);
            } elseif (!$backup_state['steps']['verification']['completed']) {
                $this->logger->info('Verifying upload', ['id' => $backup_id]);
                if ($params['uploadVerified'] === true) {
                    $backup_state['steps']['verification']['completed'] = true;
                }
            } elseif (!$backup_state['steps']['cleanup']['completed']) {
                $this->logger->info('Cleaning up', ['id' => $backup_id]);
                $this->backup_cleanup($backup_id, $backup_state);
            }

            $this->logger->debug('Backup step completed', ['id' => $backup_id, 'state' => $backup_state]);

            if ($backup_state['steps']['cleanup']['completed']) {
                $backup_state['completed_at'] = current_time('mysql');
                $backup_state['status'] = 'completed';

                return $backup_state;
            }

            $this->stateManager->store_state($backup_state);

            return $backup_state;
        } catch (Exception $e) {
            $backup_state['status'] = 'failed';
            $backup_state['completed_at'] = current_time('mysql');
            $backup_state['errors'][] = $e->getMessage();

            $this->stateManager->store_state($backup_state);

            $this->logger->error(
                'Backup failed',
                [
                    'id' => $backup_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return $backup_state;
        }
    }

    private function backup_enumerate_files_chunk(string $backup_id, array &$backup_state): void
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_list_file = $this->utils->get_files_list_file_path($backup_id);

        if (!$wp_filesystem->is_file($backup_list_file) && !$wp_filesystem->put_contents($backup_list_file, '')) {
            throw new RuntimeException('Failed to create file list');
        }

        if (!isset($backup_state['steps']['file_enumeration']['current_offset'])) {
            $backup_state['steps']['file_enumeration']['current_offset'] = 0;
            $backup_state['steps']['file_enumeration']['size'] = 0;
        }

        $current_offset = $backup_state['steps']['file_enumeration']['current_offset'];
        $chunk_config = $this->utils->calculate_chunk_size();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $i = 0;
        foreach ($iterator as $file) {
            if ($i < $current_offset) {
                $i++;
                continue;
            }

            $pathsToIgnore = array_merge([$this->utils->get_backup_root_directory()], self::BACKUP_PATHS_TO_IGNORE);

            foreach ($pathsToIgnore as $pathToIgnore) {
                if (str_contains($file->getPathname(), $pathToIgnore)) {
                    continue 2;
                }
            }

            if ($file->isDir()) {
                continue;
            }

            $write_result = file_put_contents($backup_list_file, $file->getPathname()."\n", FILE_APPEND);

            if ($write_result === false) {
                throw new RuntimeException('Failed to write file list');
            }

            $backup_state['steps']['file_enumeration']['size'] += $file->getSize();

            if (!$this->utils->is_chunk_processing_safe($chunk_config)) {
                $backup_state['steps']['file_enumeration']['current_offset'] = $i;

                return; // Another chunk needed
            }

            $backup_state['steps']['file_enumeration']['current_offset'] = $i + 1;
            $i++;
        }

        if (!$this->is_required_space_available($backup_id, $backup_state)) {
            throw new RuntimeException('Not enough space available');
        }

        $backup_state['steps']['file_enumeration']['completed'] = true;
    }

    private function is_required_space_available(string $backup_id, array $backup_state): bool
    {
        $files_size = $backup_state['steps']['files']['size'];
        $buffer_percentage = 0.1;

        $required_space = $files_size + ($files_size * $buffer_percentage);

        return disk_free_space($this->utils->get_backup_directory($backup_id)) > $required_space;
    }

    private function backup_database_chunk(string $backup_id, array &$backup_state): void
    {
        global $wpdb;

        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_file = $this->utils->get_database_backup_file_path($backup_id);

        $chunk_config = $this->utils->calculate_chunk_size('database');
        $chunk_size = $chunk_config['chunk_size'];

        if (!$this->utils->is_chunk_processing_safe($chunk_config)) {
            return;
        }

        $tables = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($wpdb->prefix).'%'
            ),
            ARRAY_N
        );

        $current_table_index = $backup_state['steps']['database']['current_table'] ?? 0;
        $current_offset = $backup_state['steps']['database']['current_offset'] ?? 0;

        if (empty($backup_state['steps']['database']['total_rows'])) {
            $total_rows = 0;
            foreach ($tables as $table) {
                $total_rows += $wpdb->get_var("SELECT COUNT(*) FROM $table[0]");
            }
            $backup_state['steps']['database']['total_rows'] = $total_rows;
        }

        $file_mode = $current_table_index > 0 || $current_offset > 0 ? FILE_APPEND : 0;
        $table_name = $tables[$current_table_index][0];

        if ($current_table_index >= count($tables)) {
            $backup_state['steps']['database']['completed'] = true;

            return;
        }

        $write_result = true;
        if ($current_offset === 0) {
            $create_table = $wpdb->get_row("SHOW CREATE TABLE $table_name", ARRAY_N);
            $write_result = file_put_contents(
                $backup_file,
                "-- Table: $table_name\n".
                $create_table[1].";\n\n",
                $file_mode
            );
        }

        if ($write_result === false) {
            throw new RuntimeException('Failed to write database chunk');
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name LIMIT %d OFFSET %d",
                $chunk_size,
                $current_offset
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            $current_table_index++;

            if ($current_table_index >= count($tables)) {
                $backup_state['steps']['database']['completed'] = true;

                return;
            }

            $backup_state['steps']['database']['current_table'] = $current_table_index;
            $backup_state['steps']['database']['current_offset'] = 0;

            $this->backup_database_chunk($backup_id, $backup_state);

            return;
        }

        $sql_content = '';
        foreach ($rows as $row) {
            $insert_values = array_map(function ($value) use ($wpdb) {
                return is_null($value) ? 'NULL' : $wpdb->_real_escape($value);
            }, $row);

            $sql_content .= "INSERT INTO $table_name VALUES ('".
                implode("', '", $insert_values)."');\n";
        }

        $write_result = file_put_contents($backup_file, $sql_content, FILE_APPEND);

        $rows_count = count($rows);

        $backup_state['steps']['database']['current_offset'] += $rows_count;
        $backup_state['steps']['database']['rows_processed'] += $rows_count;

        if ($write_result === false) {
            throw new RuntimeException('Failed to write database chunk');
        }
    }

    /**
     * @throws Exception
     */
    private function backup_add_files_to_archive(string $backup_id, array &$backup_state)
    {
        $zip = $this->utils->get_zip_archive($backup_id);

        $files_list = file(
            $this->utils->get_files_list_file_path($backup_id),
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        $current_offset = $backup_state['steps']['archive']['current_offset'] ?? 0;
        $chunk_config = $this->utils->calculate_chunk_size('archive');
        $chunk_size = $chunk_config['chunk_size'];

        for ($i = $current_offset; $i < min($current_offset + $chunk_size, count($files_list)); $i++) {
            if (!$this->utils->is_chunk_processing_safe($chunk_config)) {
                break;
            }
            $file = $files_list[$i];
            if (file_exists($file)) {
                $relative_path = str_replace(ABSPATH, '', $file);
                $backup_state['steps']['archive']['size_processed'] += filesize($file);
                $zip->addFile($file, $relative_path);
            }
        }

        $backup_state['steps']['archive']['current_offset'] = $i;

        if ($i >= count($files_list)) {
            $backup_state['steps']['archive']['completed'] = true;

            $zip->addFile(
                $this->utils->get_database_backup_file_path($backup_id),
                Utils::BACKUP_DATABASE_FILE_NAME
            );
            $zip->addFile(
                $this->utils->get_files_list_file_path($backup_id),
                Utils::BACKUP_FILES_LIST_FILE_NAME
            );
        }

        $zip->close();
    }

    /**
     * @throws Exception
     */
    private function backup_upload_chunk(string $backup_id, array $params, array &$backup_state): void
    {
        $backup_file = $this->utils->get_zip_archive($backup_id)->filename;

        $upload_part_url = $params['uploadPartUrl'] ?? null;
        $resume_data = $backup_state['steps']['upload'] ?? null;

        $upload_result = $this->chunkedUploader->upload_file(
            $backup_file,
            $upload_part_url,
            $resume_data
        );

        $backup_state['steps']['upload'] = $upload_result;
    }

    private function backup_cleanup(string $backup_id, array &$backup_state): void
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_dir = $this->utils->get_backup_directory($backup_id);
        if (empty($backup_dir)) {
            throw new RuntimeException('Failed to get backup directory');
        }

        $wp_filesystem->delete($backup_dir, true);

        $backup_state['steps']['cleanup']['completed'] = true;
    }

    public function start_backup()
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }
        $backup_dir = $this->utils->get_backup_root_directory();

        if (empty($backup_dir)) {
            $this->logger->error('Failed to get backup directory');

            return new WP_Error(
                'backup_dir_missing',
                'Backup directory is missing',
                ['status' => 500]
            );
        }

        $backup_dirs = $wp_filesystem->dirlist($backup_dir);
        foreach ($backup_dirs as $dir) {
            $wp_filesystem->delete($backup_dir.DIRECTORY_SEPARATOR.$dir['name'], true);
        }

        try {
            $this->logger->info('Starting backup');
            $this->utils->can_execute_backup();
        } catch (RuntimeException $e) {
            $this->logger->error(
                'Cannot execute backup',
                ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );

            return new WP_Error(
                500,
                'Cannot execute backup',
                ['exception' => $e->getMessage()]
            );
        }

        $backup_id = uniqid('backup_'.date('Ymd_His').'_');

        $this->logger->debug('Backup ID generated', ['backup_id' => $backup_id]);

        return $this->stateManager->create_new_backup($backup_id);
    }
}