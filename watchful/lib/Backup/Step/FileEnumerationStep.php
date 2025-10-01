<?php

namespace Watchful\Backup\Step;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Watchful\Backup\StateManager;
use Watchful\Backup\Utils;
use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;
use Watchful\Restore\DirectoryHelper;
use WP_Filesystem_Direct;

class FileEnumerationStep
{
    private const BACKUP_PATHS_TO_IGNORE = ['wp-content/ai1wm-backups', 'wp-content/backups'];

    /** @var StateManager */
    private $stateManager;

    /** @var DirectoryHelper */
    private $restoreDirectoryHelper;

    /** @var Utils */
    private $utils;

    /** @var Logger */
    private $logger;

    public function __construct(
        StateManager $stateManager,
        DirectoryHelper $restoreDirectoryHelper,
        Utils $utils,
        Logger $logger
    ) {
        $this->stateManager = $stateManager;
        $this->restoreDirectoryHelper = $restoreDirectoryHelper;
        $this->utils = $utils;
        $this->logger = $logger;
    }

    public function execute(string $backup_id, BackupState $backup_state): void
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_list_file = $this->utils->get_files_list_file_path($backup_id);
        $backup_type = $backup_state->manifest->backup_type;

        $header = ['File Path', 'Size', 'Checksum'];
        if (!$this->utils->write_csv_header($backup_list_file, $header)) {
            throw new RuntimeException('Failed to create CSV file list');
        }

        if (!isset($backup_state->file_enumeration->current_offset)) {
            $backup_state->file_enumeration->current_offset = 0;
            $backup_state->file_enumeration->size = 0;
            $backup_state->file_enumeration->total_files = 0;
            $backup_state->file_enumeration->changed_files = 0;
        }

        $current_offset = $backup_state->file_enumeration->current_offset;
        $chunk_config = $this->utils->calculate_chunk_size();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $base_backup_files = $this->stateManager->get_previous_backup_file_list($backup_id);

        if (
            $backup_type === Utils::BACKUP_TYPE_DIFFERENTIAL && empty($base_backup_files)
        ) {
            throw new RuntimeException(
                'Base backup files are required for differential backup'
            );
        }

        $pathsToIgnore = array_merge(
            [
                $this->utils->get_backup_root_directory(),
                $this->restoreDirectoryHelper->get_restore_root_directory(),
            ],
            self::BACKUP_PATHS_TO_IGNORE
        );

        $i = 0;
        foreach ($iterator as $file) {
            if ($i < $current_offset) {
                $i++;
                continue;
            }

            $backup_state->file_enumeration->current_offset = $i + 1;

            if ($this->utils->is_chunk_processing_safe($chunk_config) === false) {
                return;
            }

            $i++;

            foreach ($pathsToIgnore as $pathToIgnore) {
                if (str_contains($file->getPathname(), $pathToIgnore)) {
                    continue 2;
                }
            }

            if ($file->isDir()) {
                continue;
            }

            $file_path = $file->getPathname();
            $file_size = $file->getSize();

            $backup_state->file_enumeration->total_files++;

            if ($backup_type === Utils::BACKUP_TYPE_FULL) {
                $this->process_full_backup_file($backup_list_file, $file_path, $file_size, $backup_state);
            } elseif ($backup_type === Utils::BACKUP_TYPE_DIFFERENTIAL) {
                $this->process_differential_backup_file(
                    $backup_list_file,
                    $file_path,
                    $file_size,
                    $backup_state,
                    $base_backup_files
                );
            }
        }

        if ($backup_type === Utils::BACKUP_TYPE_FULL) {
            $backup_state->file_enumeration->completed = true;

            $this->logger->info('File enumeration completed', [
                'backup_id' => $backup_id,
                'total_files' => $backup_state->file_enumeration->total_files,
                'changed_files' => $backup_state->file_enumeration->changed_files,
                'size' => $backup_state->file_enumeration->size,
            ]);

            return;
        }

        // add list of deleted files for differential backup
        $deleted_files_list_file = $this->utils->get_files_list_deleted_file_path($backup_id);
        $deleted_files_header = ['File Path'];
        if (!$this->utils->write_csv_header($deleted_files_list_file, $deleted_files_header)) {
            throw new RuntimeException('Failed to create CSV file list for deleted files');
        }


        foreach ($base_backup_files as $file_info) {
            $relative_path = $file_info[0];
            $file_path = ABSPATH.DIRECTORY_SEPARATOR.$relative_path;

            if (file_exists($file_path)) {
                continue;
            }

            $this->utils->append_row_to_csv(
                $deleted_files_list_file,
                [$relative_path]
            );
            $backup_state->file_enumeration->deleted_files++;
        }

        $this->logger->info('File enumeration for differential backup completed', [
            'backup_id' => $backup_id,
            'total_files' => $backup_state->file_enumeration->total_files,
            'changed_files' => $backup_state->file_enumeration->changed_files,
            'deleted_files' => $backup_state->file_enumeration->deleted_files,
            'size' => $backup_state->file_enumeration->size,
        ]);

        $backup_state->file_enumeration->completed = true;
    }

    private function process_full_backup_file(
        string $backup_list_file,
        string $file_path,
        int $file_size,
        BackupState $backup_state
    ): void {
        $backup_state->file_enumeration->size += $file_size;
        $backup_state->file_enumeration->changed_files++;

        $checksum = $this->utils->get_file_checksum($file_path);
        $relative_path = str_replace(ABSPATH, '', $file_path);
        $this->utils->append_row_to_csv($backup_list_file, [$relative_path, $file_size, $checksum]);
    }

    private function process_differential_backup_file(
        string $backup_list_file,
        string $file_path,
        int $file_size,
        BackupState $backup_state,
        array $base_backup_files
    ): void {
        $checksum = $this->utils->get_file_checksum($file_path);

        $relative_path = str_replace(ABSPATH, '', $file_path);
        $old_checksum = null;
        foreach ($base_backup_files as $file_info) {
            if ($file_info[0] === $relative_path) {
                $old_checksum = $file_info[2];
                break;
            }
        }

        $this->logger->debug('Processing file for differential backup', [
            'file_path' => $file_path,
            'file_size' => $file_size,
            'relative_path' => $relative_path,
            'old_checksum' => $old_checksum,
            'new_checksum' => $checksum,
        ]);

        if (
            $old_checksum === null ||
            $old_checksum !== $checksum
        ) {
            $backup_state->file_enumeration->size += $file_size;
            $backup_state->file_enumeration->changed_files++;
            $this->utils->append_row_to_csv($backup_list_file, [$relative_path, $file_size, $checksum]);
        }
    }
}
