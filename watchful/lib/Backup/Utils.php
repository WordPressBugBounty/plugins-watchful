<?php

namespace Watchful\Backup;

use RuntimeException;
use WP_Filesystem_Direct;
use ZipArchive;

final class Utils
{
    public const BACKUP_DATABASE_FILE_NAME = 'database.sql';
    public const BACKUP_FILES_LIST_FILE_NAME = 'files_list.txt';

    public function get_zip_archive(string $backup_id): ZipArchive
    {
        $path = $this->get_backup_file($backup_id, 'backup.zip', false);

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive class not found');
        }

        $zip = new ZipArchive();

        if (file_exists($path)) {
            $open = $zip->open($path);

            if ($open !== true) {
                throw new RuntimeException('Cannot open ZIP archive');
            }

            return $zip;
        }

        $zipResult = $zip->open($path, ZipArchive::CREATE);
        if ($zipResult !== true) {
            throw new RuntimeException('Cannot create ZIP archive');
        }

        return $zip;
    }

    private function get_backup_file(string $backup_id, string $file_name, ?bool $create = true): string
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $path = $this->get_backup_directory($backup_id).DIRECTORY_SEPARATOR.$file_name;

        if ($create && !$wp_filesystem->exists($path) && !$wp_filesystem->put_contents($path, '')) {
            throw new RuntimeException('Failed to create backup file: '.$file_name);
        }

        return $path;
    }

    public function get_backup_directory(string $backup_id): string
    {
        $backup_dir = $this->get_backup_root_directory().DIRECTORY_SEPARATOR.$backup_id;

        $result = true;
        if (!file_exists($backup_dir)) {
            $result = wp_mkdir_p($backup_dir);
        }

        if ($result === false) {
            return '';
        }

        $this->add_security_files($backup_dir);

        return $backup_dir;
    }

    public function get_backup_root_directory(): string
    {
        $main_dir = WATCHFUL_PLUGIN_CONTENT_DIR;
        $result = true;
        if (!file_exists($main_dir)) {
            $result = wp_mkdir_p($main_dir);
        }

        if ($result === false) {
            $main_dir = WP_CONTENT_DIR.DIRECTORY_SEPARATOR.'watchful-backup';
        }

        if (!file_exists($main_dir)) {
            $result = wp_mkdir_p($main_dir);
        }

        if ($result === false) {
            return '';
        }

        $backup_dir = $main_dir.'/backups';

        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        $this->add_security_files($backup_dir);

        return $backup_dir;
    }

    private function add_security_files(string $path): void
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $result = true;
        $htaccess_path = $path.'/.htaccess';
        if (!file_exists($htaccess_path)) {
            $result = $wp_filesystem->put_contents($htaccess_path, "Deny from all\n");
        }

        $index_path = $path.'/index.php';
        if (!file_exists($index_path)) {
            $result = $wp_filesystem->put_contents($index_path, "<?php // Silence is golden. ?>\n");
        }

        if ($result === false) {
            throw new RuntimeException('Failed to create security files');
        }
    }

    public function get_database_backup_file_path(string $backup_id): string
    {
        return $this->get_backup_file($backup_id, self::BACKUP_DATABASE_FILE_NAME);
    }

    public function get_files_list_file_path(string $backup_id): string
    {
        return $this->get_backup_file($backup_id, self::BACKUP_FILES_LIST_FILE_NAME);
    }

    public function calculate_chunk_size(string $operation = 'default'): array
    {
        $memory_limit = $this->parse_size(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $available_memory = $memory_limit - $memory_usage;
        $max_execution_time = ini_get('max_execution_time');
        $start_time = microtime(true);

        $configs = [
            'database' => [
                'row_size' => 1024,      // Average row size in bytes
                'process_time' => 0.01,   // Time per row in seconds
                'min_chunk' => 100,
                'max_chunk' => 5000,
            ],
            'archive' => [
                'row_size' => 5120,      // Larger size for ZIP operations
                'process_time' => 0.05,   // More time for compression
                'min_chunk' => 50,
                'max_chunk' => 1000,
            ],
            'default' => [
                'row_size' => 1024,
                'process_time' => 0.01,
                'min_chunk' => 100,
                'max_chunk' => 5000,
            ],
        ];

        $config = $configs[$operation] ?? $configs['default'];

        $memory_based_chunk_size = max(
            $config['min_chunk'],
            min(
                $config['max_chunk'],
                (int)floor($available_memory / $config['row_size'])
            )
        );

        $time_based_chunk_size = max(
            $config['min_chunk'],
            min(
                $config['max_chunk'],
                (int)floor(
                    ($max_execution_time * 0.8) / $config['process_time']
                )
            )
        );

        $chunk_size = min($memory_based_chunk_size, $time_based_chunk_size);

        return [
            'chunk_size' => $chunk_size,
            'max_execution_time' => $max_execution_time,
            'start_time' => $start_time,
        ];
    }

    private function parse_size(string $size): int
    {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
        $size = preg_replace('/[^0-9.]/', '', $size);

        if ($unit) {
            return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }

        return round($size);
    }

    public function is_chunk_processing_safe(array $chunk_config): bool
    {
        $current_time = microtime(true);
        $elapsed_time = $current_time - $chunk_config['start_time'];

        return $elapsed_time < ($chunk_config['max_execution_time'] * 0.8);
    }

    public function can_execute_backup(): bool
    {
        $required_functions = [
            'file_put_contents',
            'file_get_contents',
            'json_encode',
            'json_decode',
            'disk_free_space',
        ];

        $required_extensions = [
            'zip',
            'json',
            'mysqli',
        ];

        $disabled_functions = explode(',', ini_get('disable_functions'));
        foreach ($required_functions as $function) {
            if (in_array($function, $disabled_functions)) {
                throw new RuntimeException('Function '.$function.' is disabled');
            }
        }

        foreach ($required_extensions as $extension) {
            if (!extension_loaded($extension)) {
                throw new RuntimeException('Extension '.$extension.' is not loaded');
            }
        }

        $backup_dir = $this->get_backup_root_directory();
        if (empty($backup_dir)) {
            throw new RuntimeException('Backup directory is not writable');
        }

        return true;
    }
}