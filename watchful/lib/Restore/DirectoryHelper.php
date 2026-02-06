<?php

namespace Watchful\Restore;

use RuntimeException;
use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use ZipArchive;

class DirectoryHelper
{
    /** @var Files $file_helper */
    private $file_helper;

    /** @var Logger $logger */
    private $logger;

    public function __construct(
        Files $file_helper,
        Logger $logger
    ) {
        $this->file_helper = $file_helper;
        $this->logger = $logger;
    }

    public function get_zip_archive(string $backup_id): ZipArchive
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
        ]);

        throw new RuntimeException(StepResponse::STATUS_CODE_ZIP_INVALID);
    }

    public function get_restore_directory(string $backup_id): string
    {
        $root_directory = $this->get_restore_root_directory();

        $restore_process_dir = $root_directory.DIRECTORY_SEPARATOR.$backup_id;

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

    public function get_restore_root_directory(): string
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

        return $restore_dir;
    }

    public function get_database_restore_directory(string $backup_id): string
    {
        $restore_dir = $this->get_restore_directory($backup_id);
        $database_dir = $restore_dir.DIRECTORY_SEPARATOR.'database';
        $result = true;
        if (!file_exists($database_dir)) {
            $result = wp_mkdir_p($database_dir);
        }
        if ($result === false) {
            throw new RuntimeException('Failed to create database restore directory');
        }

        return $database_dir;
    }

    public function save_json(string $backup_id, string $filename, array $data): void
    {
        $path = $this->get_restore_directory($backup_id).DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, json_encode($data));
    }

    public function load_json(string $backup_id, string $filename): array
    {
        $path = $this->get_restore_directory($backup_id).DIRECTORY_SEPARATOR.$filename;
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);

        return json_decode($content, true) ?: [];
    }
}
