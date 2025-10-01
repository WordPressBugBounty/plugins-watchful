<?php

namespace Watchful\Backup\Step;

use Exception;
use Watchful\Backup\Utils;
use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;
use ZipArchive;

class ArchiveStep
{
    /** @var Utils */
    private $utils;

    /** @var Logger */
    private $logger;

    public function __construct(Utils $utils, Logger $logger)
    {
        $this->utils = $utils;
        $this->logger = $logger;
    }

    /**
     * @throws Exception
     */
    public function execute(string $backup_id, BackupState $backup_state): void
    {
        $backup_type = $backup_state->manifest->backup_type;

        $zip = $this->utils->get_zip_archive($backup_id);

        $csv_file_path = $this->utils->get_files_list_file_path($backup_id);
        $files_data = $this->utils->parse_csv($csv_file_path);
        $files_list = array_column($files_data, 0);

        $current_offset = $backup_state->archive->current_offset ?? 0;
        $chunk_config = $this->utils->calculate_chunk_size('archive');
        $chunk_size = $chunk_config['chunk_size'];

        for ($i = $current_offset; $i < min($current_offset + $chunk_size, count($files_list)); $i++) {
            if (!$this->utils->is_chunk_processing_safe($chunk_config)) {
                break;
            }

            $relative_path = $files_data[$i][0];
            $file_path = ABSPATH.DIRECTORY_SEPARATOR.$relative_path;
            if (file_exists($file_path)) {
                $backup_state->archive->size_processed += filesize($file_path);
                $zip->addFile($file_path, $relative_path);
            }
        }

        $backup_state->archive->current_offset = $i;

        if ($i >= count($files_list)) {
            $this->finalize_archive($backup_id, $backup_type, $zip, $backup_state);
        }

        $zip->close();

        $this->logger->debug('Archive step processed', [
            'backup_id' => $backup_id,
            'current_offset' => $backup_state->archive->current_offset,
            'total_files' => count($files_list),
            'size_processed' => $backup_state->archive->size_processed,
        ]);
    }

    private function finalize_archive(
        string $backup_id,
        string $backup_type,
        ZipArchive $zip,
        BackupState $backup_state
    ): void {
        $backup_state->archive->completed = true;

        $database_backup_dir = $this->utils->get_database_backup_dir_path($backup_id);

        $zip->addEmptyDir(Utils::BACKUP_ARCHIVE_WATCHFUL_CONTENT);
        $zip->addEmptyDir(
            Utils::BACKUP_ARCHIVE_WATCHFUL_CONTENT.DIRECTORY_SEPARATOR.Utils::BACKUP_ARCHIVE_DATABASE_DIR_NAME
        );

        $files = scandir($database_backup_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $zip->addFile(
                $database_backup_dir.DIRECTORY_SEPARATOR.$file,
                Utils::BACKUP_ARCHIVE_WATCHFUL_CONTENT.DIRECTORY_SEPARATOR.Utils::BACKUP_ARCHIVE_DATABASE_DIR_NAME.DIRECTORY_SEPARATOR.$file
            );
        }

        $zip->addFile(
            $this->utils->get_files_list_file_path($backup_id),
            Utils::BACKUP_ARCHIVE_WATCHFUL_CONTENT.DIRECTORY_SEPARATOR.Utils::BACKUP_FILES_LIST_FILE_NAME
        );

        if ($backup_type === Utils::BACKUP_TYPE_DIFFERENTIAL) {
            $zip->addFile(
                $this->utils->get_files_list_deleted_file_path($backup_id),
                Utils::BACKUP_ARCHIVE_WATCHFUL_CONTENT.DIRECTORY_SEPARATOR.Utils::BACKUP_FILES_LIST_DELETED_FILE_NAME
            );
        }

        $this->logger->info('Archive creation completed', [
            'backup_id' => $backup_id,
            'backup_type' => $backup_type,
        ]);
    }
}
