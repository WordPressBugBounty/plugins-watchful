<?php

namespace Watchful\Backup;

use Exception;
use RuntimeException;
use Watchful\Backup\Step\ArchiveStep;
use Watchful\Backup\Step\CleanupStep;
use Watchful\Backup\Step\DatabaseStep;
use Watchful\Backup\Step\FileEnumerationStep;
use Watchful\Backup\Step\UploadStep;
use Watchful\Backup\Step\VerificationStep;
use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;
use Watchful\Restore\DirectoryHelper;
use WP_Error;
use WP_Filesystem_Direct;

final class Processor
{
    public const DB_PREFIX_PLACEHOLDER = 'DB_PREFIX_';

    /** @var StateManager */
    private $stateManager;

    /** @var Utils */
    private $utils;

    /** @var ChunkedUploader */
    private $chunkedUploader;

    /** @var Logger */
    private $logger;

    public function __construct()
    {
        $this->stateManager = new StateManager();
        $this->utils = new Utils();
        $this->chunkedUploader = new ChunkedUploader();
        $this->logger = new Logger('backup');
    }

    /**
     * @throws RuntimeException
     */
    public function step_backup(array $params)
    {
        $this->logger->info('Starting backup step', ['params' => $params]);

        if (empty($params['id'])) {
            $this->logger->error('Backup ID is missing', ['id' => $params]);

            return new WP_Error(
                'backup_id_missing',
                'Backup ID is required'
            );
        }

        $backup_id = $params['id'];
        $backup_state = $this->stateManager->get_state($backup_id);

        if ($backup_state->status === 'completed' || $backup_state->status === 'failed') {
            $this->logger->info('Backup completed', ['id' => $backup_id]);

            return $backup_state;
        }

        $this->logger->debug('Backup state', ['state' => $backup_state]);

        try {
            if (!$backup_state->file_enumeration->completed) {
                $this->logger->info('Enumerating files', ['id' => $backup_id]);
                $step = new FileEnumerationStep(
                    $this->stateManager,
                    new DirectoryHelper(new Files(), $this->logger),
                    $this->utils,
                    $this->logger
                );
                $step->execute($backup_id, $backup_state);
            } elseif (!$backup_state->database->completed) {
                $this->logger->info('Backing up database', ['id' => $backup_id]);
                $step = new DatabaseStep($this->utils, $this->logger);
                $step->execute($backup_id, $backup_state);
            } elseif (!$backup_state->archive->completed) {
                $this->logger->info('Adding files to archive', ['id' => $backup_id]);
                $step = new ArchiveStep($this->utils, $this->logger);
                $step->execute($backup_id, $backup_state);
            } elseif (!$backup_state->upload->completed) {
                $this->logger->info('Uploading backup', ['id' => $backup_id]);
                $step = new UploadStep($this->utils, $this->chunkedUploader, $this->logger);
                $step->execute($backup_id, $params, $backup_state);
            } elseif (!$backup_state->verification->completed) {
                $this->logger->info('Verifying upload', ['id' => $backup_id]);
                $step = new VerificationStep($this->logger);
                $step->execute($backup_id, $params, $backup_state);
            } elseif (!$backup_state->cleanup->completed) {
                $this->logger->info('Cleaning up', ['id' => $backup_id]);
                $step = new CleanupStep($this->utils, $this->logger);
                $step->execute($backup_id, $backup_state);
            }

            $this->logger->debug('Backup step completed', ['id' => $backup_id, 'state' => $backup_state]);

            if ($backup_state->cleanup->completed) {
                $backup_state->manifest->completed_at = current_time('timestamp');
                $backup_state->status = 'completed';

                return $backup_state;
            }

            $this->stateManager->store_state($backup_state);

            return $backup_state;
        } catch (Exception $e) {
            $backup_state->status = 'failed';
            $backup_state->manifest->completed_at = current_time('timestamp');
            $backup_state->errors[] = $e->getMessage();
            $this->stateManager->store_state($backup_state);
            $this->logger->error(
                'Backup failed',
                [
                    'id' => $backup_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return $backup_state;
        }
    }


    /**
     * Start a new backup with differential support
     * @return BackupState|WP_Error
     */
    public function start_backup_with_options(array $options = [])
    {
        /** @var $wp_filesystem WP_Filesystem_Direct */
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_dir = $this->utils->get_backup_root_directory();

        if (empty($backup_dir)) {
            $this->logger->error('Failed to get backup directory');

            return new WP_Error(
                'backup_dir_missing',
                'Backup directory is missing',
                ['status' => 500]
            );
        }

        $backup_dirs = $wp_filesystem->dirlist($backup_dir);
        foreach ($backup_dirs as $dir) {
            $wp_filesystem->delete($backup_dir.DIRECTORY_SEPARATOR.$dir['name'], true);
        }

        try {
            $this->logger->info('Starting backup with options', ['options' => $options]);
            $this->utils->can_execute_backup();
        } catch (RuntimeException $e) {
            $this->logger->error(
                'Cannot execute backup',
                ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );

            return new WP_Error(
                500,
                'Cannot execute backup',
                ['exception' => $e->getMessage()]
            );
        }

        $backup_id = uniqid('backup_'.current_time('timestamp').'_');
        $backup_type = $options['backup_type'] ?? Utils::BACKUP_TYPE_FULL;

        $this->logger->debug('Backup ID generated', [
            'backup_id' => $backup_id,
            'backup_type' => $backup_type,
        ]);

        if (
            $backup_type === Utils::BACKUP_TYPE_DIFFERENTIAL &&
            (
                empty($options['full_backup_id']) ||
                empty($options['file_list'])
            )
        ) {
            $this->logger->error(
                'Full backup ID or file list is required for differential backup',
                ['options' => $options]
            );

            return new WP_Error(
                'full_backup_id_missing',
                'Full backup ID or file list is required for differential backup',
                ['status' => 400]
            );
        }

        if ($backup_type === Utils::BACKUP_TYPE_DIFFERENTIAL) {
            $this->stateManager->store_previous_backup_file_list($backup_id, $options['file_list']);
        }

        return $this->stateManager->create_new_backup($backup_id, $backup_type, $options['full_backup_id'] ?? null);
    }
}
