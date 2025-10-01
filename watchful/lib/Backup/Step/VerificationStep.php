<?php

namespace Watchful\Backup\Step;

use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;

class VerificationStep
{
    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function execute(string $backup_id, array $params, BackupState $backup_state): void
    {
        $upload_verified = $params['uploadVerified'] ?? false;

        $this->logger->debug('Verifying upload', [
            'backup_id' => $backup_id,
            'upload_verified' => $upload_verified,
        ]);

        if ($upload_verified === true) {
            $backup_state->verification->completed = true;

            $this->logger->info('Upload verification completed successfully', [
                'backup_id' => $backup_id,
            ]);
        } else {
            $this->logger->debug('Upload verification pending', [
                'backup_id' => $backup_id,
            ]);
        }
    }
}
