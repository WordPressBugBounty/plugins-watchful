<?php

namespace Watchful\Restore;

use Watchful\Helpers\Files;
use Watchful\Helpers\Logger;
use Watchful\Restore\Step\CleanupStep;
use Watchful\Restore\Step\DownloadStep;
use Watchful\Restore\Step\RestoreDatabaseStep;
use Watchful\Restore\Step\RestoreDataStep;

class Processor
{
    public const LOG_CHANNEL = 'restore';
    private const STEP_ID_DOWNLOAD = 'download';
    private const STEP_ID_RESTORE_DATA = 'restore_data';
    private const STEP_ID_RESTORE_DB = 'restore_database';
    private const STEP_ID_CLEANUP = 'cleanup';

    /** @var Files $file_helper */
    private $file_helper;
    private $logger;

    public function __construct()
    {
        $this->file_helper = new Files();
        $this->logger = new Logger(self::LOG_CHANNEL);
    }

    public function step_restore(array $request): StepResponse
    {
        $backup_id = $request['id'] ?? null;
        $step_id = $request['stepId'] ?? null;
        $data = $request['data'] ?? [];

        ini_set('max_execution_time', 0);

        $this->logger->debug('Step restore', [
            'id' => $backup_id,
            'stepId' => $step_id,
        ]);

        if (empty($backup_id) || empty($step_id)) {
            $this->logger->error('Invalid request', [
                'request' => $request,
                'channel' => self::LOG_CHANNEL,
            ]);

            return new StepResponse(
                false,
                StepResponse::STATUS_CODE_INVALID_REQUEST,
                $request
            );
        }

        if ($step_id === self::STEP_ID_DOWNLOAD) {
            $step = new DownloadStep($this->file_helper, $this->logger);

            return $step->run($backup_id, $data);
        }

        if ($step_id === self::STEP_ID_RESTORE_DATA) {
            $step = new RestoreDataStep($this->file_helper, $this->logger);

            return $step->run($backup_id, $data);
        }

        if ($step_id === self::STEP_ID_RESTORE_DB) {
            $step = new RestoreDatabaseStep($this->file_helper, $this->logger);

            return $step->run($backup_id, $data);
        }

        if ($step_id === self::STEP_ID_CLEANUP) {
            $step = new CleanupStep($this->file_helper, $this->logger);

            return $step->run($backup_id, $data);
        }

        $this->logger->error('Invalid step', [
            'stepId' => $step_id,
            'request' => $request,
        ]);

        return new StepResponse(
            false,
            StepResponse::STATUS_CODE_INVALID_REQUEST,
            $request
        );
    }
}
