<?php

namespace Watchful\Restore\Step;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Watchful\Backup\Utils;
use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use Watchful\Restore\DirectoryHelper;
use Watchful\Restore\StepResponse;
use Watchful\Helpers\PhpFilesystem;

class AnalyzeStep implements StepInterface
{
    private $logger;
    private $directory_helper;
    private $excluded_paths;

    public function __construct(Files $file_helper, Logger $logger)
    {
        $this->logger = $logger;
        $this->directory_helper = new DirectoryHelper($file_helper, $logger);
        $this->excluded_paths = [
            str_replace(ABSPATH, '', WATCHFUL_PLUGIN_DIR),
            str_replace(ABSPATH, '', WATCHFUL_PLUGIN_CONTENT_DIR),
        ];
    }

    public function run(string $backup_id, array $data): StepResponse
    {
        $this->logger->debug('Analyze data', [
            'backup_id' => $backup_id,
            'data' => $data,
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
        $restore_dir = $this->directory_helper->get_restore_directory($backup_id);

        $stats = [
            'processed_files' => 0,
            'excluded_files' => 0,
        ];

        $stream = $zip->getStream(
            Utils::BACKUP_ARCHIVE_WATCHFUL_CONTENT.DIRECTORY_SEPARATOR.Utils::BACKUP_FILES_LIST_FILE_NAME
        );
        if (!$stream) {
            $this->logger->error('Failed to get stream for file list');

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_ZIP_INVALID,
                [
                    'error' => 'Failed to get stream for file list',
                ]
            );
        }

        $csv_path = $restore_dir.DIRECTORY_SEPARATOR.Utils::BACKUP_FILES_LIST_FILE_NAME;
        $csv_extracted = @file_put_contents($csv_path, stream_get_contents($stream));
        @PhpFilesystem::fclose($stream);

        $this->logger->info('Extracting file list', [
            'backup_id' => $backup_id,
            'extracted' => $csv_extracted,
            'restore_dir' => $restore_dir,
        ]);

        if ($csv_extracted === false) {
            $this->logger->error('file list not found in archive', [
                'backup_id' => $backup_id,
            ]);
            $zip->close();

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_ZIP_INVALID,
                [
                    'error' => 'file list not found in archive',
                ]
            );
        }

        $utils = new Utils();
        $files_data = $utils->parse_csv($csv_path);

        $files_to_extract = $this->get_files_to_extract($files_data, $root_dir, $this->excluded_paths, $stats);
        $files_to_delete = $this->get_files_to_delete($files_data, $root_dir, $this->excluded_paths);

        [$user_files_to_extract, $wordpress_core_files_to_extract] = $this->separate_files(
            $files_to_extract
        );

        [
            $user_files_to_delete,
            $wordpress_core_files_to_delete,
        ] = $this->separate_files($files_to_delete);

        $zip->close();

        $this->logger->info('Files separated', [
            'user_files_to_extract' => count($user_files_to_extract),
            'wordpress_core_files_to_extract' => count($wordpress_core_files_to_extract),
            'user_files_to_delete' => count($user_files_to_delete),
            'wordpress_core_files_to_delete' => count($wordpress_core_files_to_delete),
        ]);

        // Save the lists of files to be processed in subsequent steps
        $this->directory_helper->save_json($backup_id, 'user_files_to_extract.json', $user_files_to_extract);
        $this->directory_helper->save_json($backup_id, 'wordpress_core_files_to_extract.json', $wordpress_core_files_to_extract);
        $this->directory_helper->save_json($backup_id, 'user_files_to_delete.json', $user_files_to_delete);
        $this->directory_helper->save_json($backup_id, 'wordpress_core_files_to_delete.json', $wordpress_core_files_to_delete);

        return new StepResponse(
            true,
            StepResponse::STATUS_CODE_ANALYZE_COMPLETED,
            [
                'stats' => $stats,
            ]
        );
    }

    private function get_files_to_extract(
        array $files_data,
        string $root_dir,
        array $excluded_paths,
        array &$stats
    ): array {
        $files_to_extract = [];
        foreach ($files_data as $row) {
            if (!isset($row[0])) {
                continue;
            }
            $relative_path = $row[0];
            $csv_checksum = $row[2] ?? null;

            $is_excluded = false;
            foreach ($excluded_paths as $excluded_path) {
                if (strpos($relative_path, $excluded_path) === 0) {
                    $is_excluded = true;
                    break;
                }
            }
            if ($is_excluded) {
                $stats['excluded_files']++;
                continue;
            }

            $absolute_path = $root_dir.$relative_path;
            $current_checksum = file_exists($absolute_path) ? md5_file($absolute_path) : null;

            if ($csv_checksum && $current_checksum && $csv_checksum === $current_checksum) {
                continue;
            }

            $files_to_extract[] = $relative_path;
        }

        return $files_to_extract;
    }

    private function get_files_to_delete(array $files_data, string $root_dir, array $excluded_paths): array
    {
        $csv_paths = array_column($files_data, 0);
        $this->logger->debug('CSV paths', ['paths' => $csv_paths]);
        $files_to_delete = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $absolute_path = $item->getPathname();
            $relative_path = str_replace($root_dir, '', $absolute_path);

            $is_excluded = false;
            foreach ($excluded_paths as $excluded_path) {
                if (strpos($relative_path, $excluded_path) === 0) {
                    $is_excluded = true;
                    break;
                }
            }
            if ($is_excluded) {
                continue;
            }

            if ($item->isDir()) {
                $has_csv_file = false;
                foreach ($csv_paths as $csv_path) {
                    if (strpos($csv_path, $relative_path.DIRECTORY_SEPARATOR) === 0) {
                        $has_csv_file = true;
                        break;
                    }
                }
                if (!$has_csv_file && count(scandir($absolute_path)) <= 2) {
                    $files_to_delete[] = $relative_path;
                    $this->logger->debug('Adding directory to delete', ['path' => $relative_path]);
                }
            } else {
                if (!in_array($relative_path, $csv_paths, true)) {
                    $files_to_delete[] = $relative_path;
                    $this->logger->debug('Adding file to delete', ['path' => $relative_path]);
                }
            }
        }

        return $files_to_delete;
    }

    private function separate_files(array $files): array
    {
        $user_files = [];
        $wordpress_core_files = [];

        $wordpress_files = $this->get_wordpress_core_paths();

        foreach ($files as $file) {
            $is_core = false;
            foreach ($wordpress_files as $wordpress_file) {
                if (strpos($file, $wordpress_file) === 0) {
                    $is_core = true;
                    break;
                }
            }
            if ($is_core) {
                $wordpress_core_files[] = $file;
            } else {
                $user_files[] = $file;
            }
        }

        return [$user_files, $wordpress_core_files];
    }

    // https://codex.wordpress.org/WordPress_Files
    private function get_wordpress_core_paths(): array
    {
        return [
            '.htaccess',
            'index.php',
            'readme.html',
            'license.txt',
            'wp-activate.php',
            'wp-admin/',
            'wp-blog-header.php',
            'wp-comments-post.php',
            'wp-config-docker.php',
            'wp-config-sample.php',
            'wp-config.php',
            'wp-cron.php',
            'wp-includes/',
            'wp-links-opml.php',
            'wp-load.php',
            'wp-login.php',
            'wp-mail.php',
            'wp-settings.php',
            'wp-signup.php',
            'wp-trackback.php',
            'xmlrpc.php',
        ];
    }
}
