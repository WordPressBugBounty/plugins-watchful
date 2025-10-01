<?php

namespace Watchful\Backup\Step;

use Exception;
use Watchful\Backup\ChunkedUploader;
use Watchful\Backup\Utils;
use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;

class UploadStep
{
    /** @var Utils */
    private $utils;

    /** @var ChunkedUploader */
    private $chunkedUploader;

    /** @var Logger */
    private $logger;

    public function __construct(Utils $utils, ChunkedUploader $chunkedUploader, Logger $logger)
    {
        $this->utils = $utils;
        $this->chunkedUploader = $chunkedUploader;
        $this->logger = $logger;
    }

    /**
     * @throws Exception
     */
    public function execute(string $backup_id, array $params, BackupState $backup_state): void
    {
        $backup_file = $this->utils->get_backup_file_path($backup_id);

        $upload_part_url = $params['uploadPartUrl'] ?? null;
        $resume_data = $backup_state->upload;

        $this->logger->debug('Starting upload chunk', [
            'backup_id' => $backup_id,
            'backup_file' => $backup_file,
            'upload_part_url' => $upload_part_url ? 'present' : 'missing',
        ]);

        $this->chunkedUploader->upload_file(
            $backup_file,
            $upload_part_url,
            $resume_data
        );

        $this->logger->debug('Upload chunk completed', [
            'backup_id' => $backup_id,
            'upload_completed' => $backup_state->upload->completed ?? false,
        ]);
    }
}
