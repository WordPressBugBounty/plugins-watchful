<?php

namespace Watchful\Restore\Step;

use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use Watchful\Restore\DirectoryHelper;
use Watchful\Restore\StepResponse;
use Watchful\Helpers\PhpFilesystem;

class DownloadStep implements StepInterface
{
    private $logger;
    /** @var DirectoryHelper $directory_helper */
    private $directory_helper;

    public function __construct(Files $file_helper, Logger $logger)
    {
        $this->logger = $logger;
        $this->directory_helper = new DirectoryHelper($file_helper, $logger);
    }

    public function run(string $backup_id, array $data): StepResponse
    {
        $url = $data['url'] ?? null;
        if (empty($url)) {
            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_INVALID_REQUEST,
                $data
            );
        }

        $filename = 'backup.zip';
        $backup_dir = $this->directory_helper->get_restore_directory($backup_id);
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
            'url' => $url,
            'file_path' => $file_path,
        ]);

        $head_response = wp_remote_head(
            $url,
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
            return $this->direct_download($url, $file_path, $file_size);
        }

        return $this->chunked_download($url, $file_path, $file_size);
    }

    private function direct_download(string $url, string $file_path, int $file_size): StepResponse
    {
        $this->logger->debug('Direct download', [
            'file_size' => $file_size,
        ]);

        $response = wp_remote_get(
            $url,
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

    private function chunked_download(string $url, string $file_path, int $file_size): StepResponse
    {
        $fp = @PhpFilesystem::fopen($file_path, 'wb');
        if (!$fp) {
            $this->logger->error('Failed to open file for writing', [
                'file_path' => $file_path,
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
                $url,
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

            $write_result = @PhpFilesystem::fwrite($fp, $data);
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

        @PhpFilesystem::fclose($fp);

        if ($error_message !== null) {
            $this->logger->error('Chunked download failed', [
                'error' => $error_message,
                'file_path' => $file_path,
            ]);
            wp_delete_file($file_path);

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
            ]);
            wp_delete_file($file_path);

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
}
