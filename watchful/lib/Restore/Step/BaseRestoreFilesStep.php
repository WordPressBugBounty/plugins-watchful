<?php

namespace Watchful\Restore\Step;

use RuntimeException;
use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use Watchful\Restore\DirectoryHelper;
use Watchful\Restore\StepResponse;
use WP_Filesystem_Base;
use ZipArchive;

abstract class BaseRestoreFilesStep implements StepInterface
{
    private const CHUNK_SIZE = 100;

    protected $logger;
    protected $directory_helper;

    public function __construct(Files $file_helper, Logger $logger)
    {
        $this->logger = $logger;
        $this->directory_helper = new DirectoryHelper($file_helper, $logger);
    }

    public function run(string $backup_id, array $data): StepResponse
    {
        $this->logger->debug('Restore ' . $this->get_log_name(), [
            'backup_id' => $backup_id,
            'data'      => $data,
        ]);
        try {
            $zip = $this->directory_helper->get_zip_archive($backup_id);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to open ZIP archive');

            return new StepResponse(
                false,
                $exception->getMessage(),
                [
                    'backup_id' => $backup_id,
                ]
            );
        }

        $root_dir = ABSPATH;

        $stats = [
            'copied_files'  => 0,
            'deleted_files' => 0,
            'skipped_files' => 0,
            'errors'        => [],
        ];

        $files_to_extract = $this->directory_helper->load_json($backup_id, $this->get_extract_filename());
        $files_to_delete = $this->directory_helper->load_json($backup_id, $this->get_delete_filename());

        $this->extract_files($zip, $files_to_extract, $root_dir, $stats, $backup_id);
        $this->delete_files($files_to_delete, $root_dir, $stats, $backup_id);

        $zip->close();

        $is_completed = empty($files_to_extract) && empty($files_to_delete);

        if (count($stats['errors']) > 0) {
            $this->logger->warning('Restore ' . $this->get_log_name() . ' processed with errors', [
                'errors'    => $stats['errors'],
                'completed' => $is_completed,
            ]);

            return new StepResponse(
                $is_completed,
                $this->get_error_status_code(),
                [
                    'stats' => $stats,
                ]
            );
        }

        if ($is_completed) {
            $this->logger->info('Restore ' . $this->get_log_name() . ' completed successfully', [
                'stats' => $stats,
            ]);

            return new StepResponse(
                true,
                $this->get_completed_status_code(),
                [
                    'stats' => $stats,
                ]
            );
        }

        $this->logger->info('Restore ' . $this->get_log_name() . ' partially completed', [
            'stats' => $stats,
        ]);

        return new StepResponse(
            false,
            $this->get_partial_status_code(),
            [
                'stats' => $stats,
            ]
        );
    }

    abstract protected function get_log_name(): string;

    abstract protected function get_extract_filename(): string;

    abstract protected function get_delete_filename(): string;

    private function extract_files(
        ZipArchive $zip,
        array &$files_to_extract,
        string $root_dir,
        array &$stats,
        string $backup_id
    ): void {
        $processed_files = 0;
        foreach ($files_to_extract as $key => $file) {
            if ($processed_files >= self::CHUNK_SIZE) {
                break;
            }
            $processed_files++;

            $relative_path = $file;

            if (empty($relative_path)) {
                continue;
            }

            $destination_path = $root_dir . $relative_path;
            $destination_dir = dirname($destination_path);

            if (!file_exists($destination_dir)) {
                if (!wp_mkdir_p($destination_dir)) {
                    $stats['errors'][] = "Failed to create directory: {$destination_dir}";
                    $stats['skipped_files']++;
                    continue;
                }
            }

            $file_info = $zip->statName($file);
            if ($file_info['size'] === 0 && substr($file, -1) === '/') {
                if (!file_exists($destination_path)) {
                    wp_mkdir_p($destination_path);
                }
                continue;
            }

            $file_content = $zip->getFromName($file);
            if ($file_content === false) {
                $stats['errors'][] = "Failed to read file from archive: {$file}";
                $stats['skipped_files']++;
                continue;
            }

            $bytes_written = file_put_contents($destination_path, $file_content);
            if ($bytes_written === false) {
                $stats['errors'][] = "Failed to write file: {$destination_path}";
                $stats['skipped_files']++;
            } else {
                $stats['copied_files']++;
            }

            unset($files_to_extract[$key], $file_content);
        }

        $this->directory_helper->save_json($backup_id, $this->get_extract_filename(), $files_to_extract);
    }

    private function delete_files(
        array &$files_to_delete,
        string $root_dir,
        array &$stats,
        string $backup_id
    ): void {
        $processed_files = 0;
        foreach ($files_to_delete as $key => $relative_path) {
            if ($processed_files >= self::CHUNK_SIZE) {
                break;
            }
            $processed_files++;

            $absolute_path = $root_dir . $relative_path;
            if (is_dir($absolute_path)) {
                if ($this->wp_filesystem()->rmdir($absolute_path)) {
                    $stats['deleted_files']++;
                } else {
                    $stats['errors'][] = "Failed to delete empty directory: {$absolute_path}";
                }
            } else {
                if ($this->wp_filesystem()->delete($absolute_path)) {
                    $stats['deleted_files']++;
                } else {
                    $stats['errors'][] = "Failed to delete file: {$absolute_path}";
                }
            }
            unset($files_to_delete[$key]);
        }
        $this->directory_helper->save_json($backup_id, $this->get_delete_filename(), $files_to_delete);
    }

    private function wp_filesystem(): WP_Filesystem_Base
    {
        global $wp_filesystem;
        if (!$wp_filesystem instanceof WP_Filesystem_Base) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem;
    }

    abstract protected function get_error_status_code(): string;

    abstract protected function get_completed_status_code(): string;

    abstract protected function get_partial_status_code(): string;
}
