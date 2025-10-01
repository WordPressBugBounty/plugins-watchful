<?php

namespace Watchful\Backup\Step;

use RuntimeException;
use Watchful\Backup\Utils;
use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;
use WP_Filesystem_Direct;

class CleanupStep
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

    public function execute(string $backup_id, BackupState $backup_state): void
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

        $this->logger->debug('Starting cleanup', [
            'backup_id' => $backup_id,
            'backup_dir' => $backup_dir,
        ]);

        $result = $wp_filesystem->delete($backup_dir, true);

        if (!$result) {
            throw new RuntimeException('Failed to delete backup directory: '.$backup_dir);
        }

        $backup_state->cleanup->completed = true;

        $this->logger->info('Cleanup completed successfully', [
            'backup_id' => $backup_id,
            'backup_dir' => $backup_dir,
        ]);
    }
}
