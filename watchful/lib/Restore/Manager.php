<?php

namespace Watchful\Restore;

use RuntimeException;
use Watchful\Backup\Processor;
use Watchful\Backup\Utils;
use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use WP_Filesystem_Direct;
use ZipArchive;

class Manager
{
    public const LOG_CHANNEL = 'restore';
    private const STEP_ID_DOWNLOAD = 'download';
    private const STEP_ID_RESTORE_DATA = 'restore_data';
    private const STEP_ID_RESTORE_DB = 'restore_database';
    private const STEP_ID_CLEANUP = 'cleanup';


    /** @var Files $file_helper */
    private $file_helper;
    private $logger;
    /** @var array */
    private $request;

    public function __construct()
    {
        $this->file_helper = new Files();
        $this->logger = new Logger(self::LOG_CHANNEL);
    }

    public function step_restore(array $request): StepResponse
    {
        $this->request = $request;
        $backup_id = $this->request['id'] ?? null;
        $step_id = $this->request['stepId'] ?? null;

        $this->logger->debug('Step restore', [
            'id' => $backup_id,
            'stepId' => $step_id,
        ]);

        if (empty($backup_id) || empty($step_id)) {
            $this->logger->error('Invalid request', [
                'request' => $this->request,
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_INVALID_REQUEST,
                $this->request
            );
        }

        if ($step_id === self::STEP_ID_DOWNLOAD) {
            return $this->download_backup();
        }

        if ($step_id === self::STEP_ID_RESTORE_DATA) {
            return $this->restore_data();
        }

        if ($step_id === self::STEP_ID_RESTORE_DB) {
            return $this->restore_database();
        }

        if ($step_id === self::STEP_ID_CLEANUP) {
            return $this->cleanup();
        }

        $this->logger->error('Invalid step', [
            'stepId' => $step_id,
            'request' => $this->request,
        ]);

        return new StepResponse(
            false,
            StepResponse::STATUS_CODE_INVALID_REQUEST,
            $this->request
        );
    }

    private function download_backup(): StepResponse
    {
        if (empty($this->request['url'])) {
            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_INVALID_REQUEST,
                $this->request
            );
        }

        $backup_id = $this->request['id'];
        $filename = 'backup.zip';
        $backup_dir = $this->get_restore_directory($backup_id);
        $file_path = $backup_dir.'/'.$filename;

        if (file_exists($file_path)) {
            return new StepResponse(
                true,
                StepResponse::STATUS_CODE_DOWNLOAD_COMPLETE,
                [
                    'file_path' => $file_path,
                    'file_size' => filesize($file_path),
                    'method' => 'cached',
                ]
            );
        }

        $this->logger->debug('Download backup', [
            'url' => $this->request['url'],
            'file_path' => $file_path,
        ]);

        $head_response = wp_remote_head(
            $this->request['url'],
            [
                'timeout' => 30,
                'headers' => [
                    'Accept-Encoding' => 'identity',
                ],
            ]
        );

        if (is_wp_error($head_response)) {
            $this->logger->error('HEAD request failed', [
                'error' => $head_response->get_error_message(),
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_DOWNLOAD_FAILED,
                [
                    'error' => 'HEAD request failed: '.$head_response->get_error_message(),
                ]
            );
        }

        $file_size = (int)wp_remote_retrieve_header($head_response, 'content-length');
        $accept_ranges = wp_remote_retrieve_header($head_response, 'accept-ranges');
        $supports_range = !empty($accept_ranges) && $accept_ranges !== 'none';

        $this->logger->debug('HEAD response', [
            'file_size' => $file_size,
            'accept_ranges' => $accept_ranges,
            'supports_range' => $supports_range,
        ]);

        if (!$supports_range || $file_size < 10 * 1024 * 1024 || empty($file_size)) {
            $this->logger->debug('Direct download', [
                'file_size' => $file_size,
                'channel' => self::LOG_CHANNEL,
            ]);
            $response = wp_remote_get(
                $this->request['url'],
                [
                    'timeout' => 300,
                    'sslverify' => false,
                    'stream' => true,
                    'filename' => $file_path,
                    'headers' => [
                        'Accept-Encoding' => 'identity',
                    ],
                ]
            );

            if (is_wp_error($response)) {
                $this->logger->error('Direct download failed', [
                    'error' => $response->get_error_message(),
                    'channel' => self::LOG_CHANNEL,
                ]);

                return new StepResponse(
                    false,
                    StepResponse::STATUS_CODE_DOWNLOAD_FAILED,
                    [
                        'error' => 'Full download failed: '.$response->get_error_message(),
                    ]
                );
            }

            if (!file_exists($file_path) || filesize($file_path) === 0) {
                $this->logger->error('Downloaded file is empty or missing', [
                    'file_path' => $file_path,
                    'channel' => self::LOG_CHANNEL,
                ]);

                return new StepResponse(
                    false,
                    StepResponse::STATUS_CODE_DOWNLOAD_FAILED,
                    [
                        'error' => 'Downloaded file is empty or missing',
                    ]
                );
            }

            $actual_size = filesize($file_path);
            $this->logger->debug('Direct download complete', [
                'file_path' => $file_path,
                'actual_size' => $actual_size,
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                true,
                StepResponse::STATUS_CODE_DOWNLOAD_COMPLETE,
                [
                    'file_path' => $file_path,
                    'file_size' => $actual_size,
                    'method' => 'direct',
                ]
            );
        }

        $fp = @fopen($file_path, 'wb');
        if (!$fp) {
            $this->logger->error('Failed to open file for writing', [
                'file_path' => $file_path,
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                false,
                'download_failed',
                [
                    'error' => 'Failed to open file for writing',
                ]
            );
        }

        $chunk_size = 5 * 1024 * 1024;
        $downloaded = 0;
        $error_message = null;

        $this->logger->debug('Chunked download', [
            'file_size' => $file_size,
            'chunk_size' => $chunk_size,
        ]);

        while ($downloaded < $file_size) {
            $end = min($downloaded + $chunk_size - 1, $file_size - 1);

            $response = wp_remote_get(
                $this->request['url'],
                [
                    'timeout' => 60,
                    'sslverify' => false,
                    'headers' => [
                        'Accept-Encoding' => 'identity',
                        'Range' => sprintf('bytes=%d-%d', $downloaded, $end),
                    ],
                ]
            );

            if (is_wp_error($response)) {
                $error_message = 'Download failed at '.$downloaded.' bytes: '.$response->get_error_message();
                break;
            }

            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 206) {
                $error_message = 'Download failed with HTTP code: '.$http_code.
                    ' (expected 206 Partial Content)';
                break;
            }

            $data = wp_remote_retrieve_body($response);
            $bytes_count = strlen($data);

            if ($bytes_count === 0) {
                if ($downloaded >= $file_size - 1) {
                    break;
                }
                $error_message = 'Empty response received for chunk';
                break;
            }

            $write_result = @fwrite($fp, $data);
            if ($write_result === false || $write_result !== $bytes_count) {
                $error_message = 'Failed to write chunk data to file';
                break;
            }

            $downloaded += $bytes_count;

            fflush($fp);
            unset($data);
            unset($response);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        @fclose($fp);

        if ($error_message !== null) {
            $this->logger->error('Chunked download failed', [
                'error' => $error_message,
                'file_path' => $file_path,
                'channel' => self::LOG_CHANNEL,
            ]);
            @unlink($file_path);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_DOWNLOAD_FAILED,
                [
                    'error' => $error_message,
                ]
            );
        }

        $actual_size = filesize($file_path);
        if ($actual_size !== $file_size) {
            $this->logger->error('File size mismatch', [
                'expected_size' => $file_size,
                'actual_size' => $actual_size,
                'file_path' => $file_path,
                'channel' => self::LOG_CHANNEL,
            ]);
            @unlink($file_path);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_DOWNLOAD_FAILED,
                [
                    'error' => "File size mismatch: expected {$file_size}, got {$actual_size}",
                ]
            );
        }

        $this->logger->debug('Chunked download complete', [
            'file_path' => $file_path,
            'file_size' => $actual_size,
        ]);

        return new StepResponse(
            true,
            StepResponse::STATUS_CODE_DOWNLOAD_COMPLETE,
            [
                'file_path' => $file_path,
                'file_size' => $file_size,
                'method' => 'chunked',
            ]
        );
    }

    private function get_restore_directory(string $backup_id): string
    {
        $main_dir = WATCHFUL_PLUGIN_CONTENT_DIR;
        $result = true;
        if (!file_exists($main_dir)) {
            $result = wp_mkdir_p($main_dir);
        }

        if ($result === false) {
            $main_dir = WP_CONTENT_DIR.DIRECTORY_SEPARATOR.'watchful-restore';
        }

        if (!file_exists($main_dir)) {
            $result = wp_mkdir_p($main_dir);
        }

        if ($result === false) {
            throw new RuntimeException('Failed to create restore directory');
        }

        $restore_dir = $main_dir.'/restore';

        if (!file_exists($restore_dir)) {
            wp_mkdir_p($restore_dir);
        }

        $this->file_helper->add_security_files($restore_dir);

        $restore_process_dir = $restore_dir.DIRECTORY_SEPARATOR.$backup_id;

        $result = true;
        if (!file_exists($restore_process_dir)) {
            $result = wp_mkdir_p($restore_process_dir);
        }

        if ($result === false) {
            throw new RuntimeException('Failed to create restore process directory');
        }

        $this->file_helper->add_security_files($restore_process_dir);

        return $restore_process_dir;
    }

    private function restore_data(): StepResponse
    {
        $backup_id = $this->request['id'];
        try {
            $zip = $this->get_zip_archive($backup_id);
        } catch (RuntimeException $exception) {
            return new StepResponse(
                false,
                $exception->getMessage(),
                [
                    'backup_id' => $backup_id,
                ]
            );
        }

        $wp_content_dir = WP_CONTENT_DIR;

        $stats = [
            'processed_files' => 0,
            'copied_files' => 0,
            'skipped_files' => 0,
            'excluded_files' => 0,
            'deleted_files' => 0,
            'errors' => [],
        ];

        $prefix_to_extract = 'wp-content/';
        $files_to_extract = [];
        $files_in_archive = [];

        $watchful_plugin_path = str_replace(ABSPATH, '', WATCHFUL_PLUGIN_DIR);
        $watchful_content_path = str_replace(ABSPATH, '', WATCHFUL_PLUGIN_CONTENT_DIR);

        $this->logger->debug('Extracting files', [
            'prefix_to_extract' => $prefix_to_extract,
            'watchful_plugin_path' => $watchful_plugin_path,
            'watchful_content_path' => $watchful_content_path,
        ]);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $file_name = $zip->getNameIndex($i);

            if (strpos($file_name, $prefix_to_extract) === 0) {
                $relative_path = substr($file_name, strlen($prefix_to_extract));

                if (
                    strpos($file_name, $watchful_plugin_path) === 0 ||
                    strpos($file_name, $watchful_content_path) === 0
                ) {
                    $stats['excluded_files']++;
                    continue;
                }

                $files_to_extract[] = $file_name;

                if (!empty($relative_path)) {
                    $file_info = $zip->statName($file_name);
                    if (!($file_info['size'] === 0 && substr($file_name, -1) === '/')) {
                        $files_in_archive[$relative_path] = true;
                    }
                }
            }
        }

        if (empty($files_to_extract)) {
            $this->logger->error('No valid wp-content files found', [
                'backup_id' => $backup_id,
                'excluded_files' => $stats['excluded_files'],
                'channel' => self::LOG_CHANNEL,
            ]);
            $zip->close();

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_ZIP_INVALID,
                [
                    'error' => 'No valid wp-content files found in the archive',
                    'excluded' => $stats['excluded_files'],
                ]
            );
        }

        $chunk_size = 100;
        $chunks = array_chunk($files_to_extract, $chunk_size);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $file) {
                $stats['processed_files']++;

                $relative_path = substr($file, strlen($prefix_to_extract));
                if (empty($relative_path)) {
                    continue;
                }

                if (
                    strpos($file, $watchful_plugin_path) === 0 ||
                    strpos($file, $watchful_content_path) === 0
                ) {
                    $stats['excluded_files']++;
                    continue;
                }

                $destination_path = $wp_content_dir.'/'.$relative_path;
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

                unset($file_content);
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $this->logger->debug('Checking for files to delete', [
            'wp_content_dir' => $wp_content_dir,
        ]);

        $scan_and_delete = function ($directory, $relative_prefix = '') use (
            &$scan_and_delete,
            &$stats,
            $files_in_archive,
            $watchful_plugin_path,
            $watchful_content_path,
            $wp_content_dir
        ) {
            $items = scandir($directory);
            if ($items === false) {
                $stats['errors'][] = "Failed to scan directory: {$directory}";

                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = $directory.'/'.$item;
                $relative_path = $relative_prefix.$item;

                $absolute_path = str_replace(ABSPATH, '', $path);
                if (
                    strpos($absolute_path, $watchful_plugin_path) === 0 ||
                    strpos($absolute_path, $watchful_content_path) === 0
                ) {
                    continue;
                }

                if (is_dir($path)) {
                    $scan_and_delete($path, $relative_path.'/');

                    if (!isset($files_in_archive[$relative_path.'/']) && count(scandir($path)) <= 2) {
                        if (rmdir($path)) {
                            $stats['deleted_files']++;
                        } else {
                            $stats['errors'][] = "Failed to delete empty directory: {$path}";
                        }
                    }
                } else {
                    if (!isset($files_in_archive[$relative_path])) {
                        if (unlink($path)) {
                            $stats['deleted_files']++;
                        } else {
                            $stats['errors'][] = "Failed to delete file: {$path}";
                        }
                    }
                }
            }
        };

        $scan_and_delete($wp_content_dir);

        $zip->close();

        if (count($stats['errors']) > 0) {
            $this->logger->error('Restore completed with errors', [
                'errors' => $stats['errors'],
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                true,
                StepResponse::STATUS_CODE_RESTORE_DATA_ERRORS,
                [
                    'stats' => $stats,
                ]
            );
        }

        $this->logger->info('Restore data completed successfully', [
            'stats' => $stats,
        ]);

        return new StepResponse(
            true,
            StepResponse::STATUS_CODE_RESTORE_DATA_COMPLETED,
            [
                'stats' => $stats,
            ]
        );
    }

    private function get_zip_archive(string $backup_id): ZipArchive
    {
        $backup_dir = $this->get_restore_directory($backup_id);
        $archive_path = $backup_dir.'/backup.zip';

        $this->logger->debug('Get ZIP archive', [
            'backup_id' => $backup_id,
            'archive_path' => $archive_path,
        ]);

        if (!file_exists($archive_path)) {
            $this->logger->error('Archive file not found', [
                'archive_path' => $archive_path,
                'channel' => self::LOG_CHANNEL,
            ]);

            throw new RuntimeException(StepResponse::STATUS_CODE_ZIP_NOT_FOUND);
        }

        $zip = new ZipArchive();
        $result = $zip->open($archive_path);

        if ($result === true) {
            return $zip;
        }

        $this->logger->error('Failed to open archive', [
            'archive_path' => $archive_path,
            'error_code' => $result,
            'channel' => self::LOG_CHANNEL,
        ]);

        throw new RuntimeException(StepResponse::STATUS_CODE_ZIP_INVALID);
    }

    private function restore_database(): StepResponse
    {
        global $wpdb;

        $backup_id = $this->request['id'];
        $backup_dir = $this->get_restore_directory($backup_id);

        $this->logger->debug('Restore database', [
            'backup_id' => $backup_id,
        ]);

        try {
            $zip = $this->get_zip_archive($backup_id);
        } catch (RuntimeException $exception) {
            return new StepResponse(
                false,
                $exception->getMessage(),
                [
                    'backup_id' => $backup_id,
                ]
            );
        }

        $extraction = $zip->extractTo($backup_dir, Utils::BACKUP_DATABASE_FILE_NAME);

        if ($extraction === false) {
            $this->logger->error('Database file not found in archive', [
                'backup_id' => $backup_id,
                'channel' => self::LOG_CHANNEL,
            ]);
            $zip->close();

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_RESTORE_DATABASE_FILE_NOT_FOUND
            );
        }

        $zip->close();

        $stats = [
            'total_queries' => 0,
            'executed_queries' => 0,
            'errors' => [],
            'transactions' => [],
            'queries' => [],
        ];

        $temp_sql_file = $backup_dir.'/'.Utils::BACKUP_DATABASE_FILE_NAME;

        $handler = @fopen($temp_sql_file, 'r');

        if ($handler === false) {
            $this->logger->error('Failed to open SQL file', [
                'file_path' => $temp_sql_file,
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_RESTORE_DATABASE_FILE_NOT_FOUND
            );
        }

        $buffer = '';
        $in_transaction = false;

        while (($line = fgets($handler)) !== false) {
            $line = trim($line);

            if (empty($line) || preg_match('/^--/', $line) || preg_match('/^#/', $line)) {
                continue;
            }

            if (preg_match('/^START\s+TRANSACTION/i', $line)) {
                $in_transaction = true;
                $wpdb->query('START TRANSACTION');
                $stats['total_queries']++;
                $stats['executed_queries']++;
                continue;
            }

            if (preg_match('/^COMMIT/i', $line)) {
                if ($in_transaction) {
                    $wpdb->query('COMMIT');
                    $stats['total_queries']++;
                    $stats['executed_queries']++;
                    $in_transaction = false;
                }
                continue;
            }

            $buffer .= $line.' ';

            if (substr($line, -1) !== ';') {
                continue;
            }

            $stats['total_queries']++;
            $query = $this->search_and_replace_sql($buffer);
            $query_log = strlen($query) > 500 ? substr($query, 0, 500).'...' : $query;

            $result = $wpdb->query($query);
            $buffer = '';

            if ($result !== false) {
                $stats['executed_queries']++;
                continue;
            }

            $this->logger->error('Error executing SQL query', [
                'error' => $wpdb->last_error,
                'query' => $query_log,
                'channel' => self::LOG_CHANNEL,
            ]);

            $stats['errors'][] = [
                'error' => $wpdb->last_error,
                'query' => $query_log,
            ];

            if ($in_transaction) {
                $wpdb->query('ROLLBACK');
                $in_transaction = false;
            }
        }

        @fclose($handler);

        @unlink($temp_sql_file);

        if (count($stats['errors']) > 0) {
            $this->logger->error('Database restore completed with errors', [
                'errors' => $stats['errors'],
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                true,
                StepResponse::STATUS_CODE_RESTORE_DATABASE_ERRORS,
                [
                    'stats' => $stats,
                ]
            );
        }

        $this->logger->info('Database restore completed successfully', [
            'stats' => $stats,
        ]);

        return new StepResponse(
            true,
            StepResponse::STATUS_CODE_RESTORE_DATABASE_COMPLETED,
            [
                'stats' => $stats,
            ]
        );
    }

    private function search_and_replace_sql(string $sql): string
    {
        global $wpdb;

        $entities = [
            [
                'search' => Processor::DB_PREFIX_PLACEHOLDER,
                'replace' => $wpdb->prefix,
            ],
        ];

        foreach ($entities as $entity) {
            $sql = str_replace($entity['search'], $entity['replace'], $sql);
        }

        return $sql;
    }

    private function cleanup(): StepResponse
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_id = $this->request['id'] ?? null;

        if (empty($backup_id)) {
            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_INVALID_REQUEST,
                $this->request
            );
        }

        $backup_dir = $this->get_restore_directory($backup_id);
        $result = $wp_filesystem->delete($backup_dir, true);

        if ($result === false) {
            $this->logger->error('Failed to delete restore directory', [
                'backup_id' => $backup_id,
                'backup_dir' => $backup_dir,
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_CLEANUP_FAILED
            );
        }

        return new StepResponse(
            true,
            StepResponse::STATUS_CODE_CLEANUP_COMPLETE
        );
    }
}
