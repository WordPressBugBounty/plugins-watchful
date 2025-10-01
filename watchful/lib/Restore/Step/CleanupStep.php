<?php

namespace Watchful\Restore\Step;

use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use Watchful\Restore\DirectoryHelper;
use Watchful\Restore\StepResponse;
use WP_Filesystem_Direct;

class CleanupStep implements StepInterface
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
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $restore_dir = $this->directory_helper->get_restore_directory($backup_id);
        $result = $wp_filesystem->delete($restore_dir, true);

        if ($result === false) {
            $this->logger->error('Failed to delete restore directory', [
                'backup_id' => $backup_id,
                'restore_dir' => $restore_dir,
            ]);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_CLEANUP_FAILED
            );
        }

        $this->logger->info('Cleanup completed successfully', [
            'backup_id' => $backup_id,
            'restore_dir' => $restore_dir,
        ]);

        return new StepResponse(
            true,
            StepResponse::STATUS_CODE_CLEANUP_COMPLETE
        );
    }
}
