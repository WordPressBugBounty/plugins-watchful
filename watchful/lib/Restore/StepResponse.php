<?php

namespace Watchful\Restore;

use JsonSerializable;

class StepResponse implements JsonSerializable
{
    public const STATUS_CODE_INVALID_REQUEST = 'invalid_request';
    public const STATUS_CODE_DOWNLOAD_FAILED = 'download_failed';
    public const STATUS_CODE_ZIP_NOT_FOUND = 'zip_not_found';
    public const STATUS_CODE_ZIP_INVALID = 'zip_invalid';
    public const STATUS_CODE_RESTORE_DATABASE_FILE_NOT_FOUND = 'database_file_not_found';
    public const STATUS_CODE_CLEANUP_FAILED = 'cleanup_failed';
    public const STATUS_CODE_DOWNLOAD_COMPLETE = 'download_complete';
    public const STATUS_CODE_RESTORE_DATA_COMPLETED = 'restore_data_completed';
    public const STATUS_CODE_RESTORE_DATA_ERRORS = 'restore_data_errors';
    public const STATUS_CODE_RESTORE_DATABASE_ERRORS = 'restore_database_errors';
    public const STATUS_CODE_RESTORE_DATABASE_COMPLETED = 'restore_database_completed';
    public const STATUS_CODE_RESTORE_DATABASE_PROCESSING = 'restore_database_processing';
    public const STATUS_CODE_CLEANUP_COMPLETE = 'cleanup_complete';
    private $step_completed;
    private $data;
    private $status_code;

    public function __construct(
        bool $step_completed,
        string $status_code,
        ?array $data = null
    ) {
        $this->step_completed = $step_completed;
        $this->status_code = $status_code;
        $this->data = $data;
    }

    public function jsonSerialize(): array
    {
        return [
            'statusCode' => $this->status_code,
            'stepCompleted' => $this->step_completed,
            'canContinue' => $this->can_continue(),
            'progress' => $this->get_progress(),
            'data' => $this->data,
        ];
    }

    public function can_continue(): bool
    {
        switch ($this->status_code) {
            case self::STATUS_CODE_DOWNLOAD_COMPLETE:
            case self::STATUS_CODE_RESTORE_DATA_COMPLETED:
            case self::STATUS_CODE_RESTORE_DATABASE_COMPLETED:
            case self::STATUS_CODE_RESTORE_DATABASE_PROCESSING:
            case self::STATUS_CODE_CLEANUP_COMPLETE:
            case self::STATUS_CODE_RESTORE_DATA_ERRORS:
            case self::STATUS_CODE_RESTORE_DATABASE_ERRORS:
            case self::STATUS_CODE_CLEANUP_FAILED:
                return true;
            default:
                return false;
        }
    }

    public function get_progress(): int
    {
        switch ($this->status_code) {
            case self::STATUS_CODE_DOWNLOAD_COMPLETE:
                return 25;
            case self::STATUS_CODE_RESTORE_DATA_COMPLETED:
            case self::STATUS_CODE_RESTORE_DATA_ERRORS:
                return 50;
            case self::STATUS_CODE_RESTORE_DATABASE_PROCESSING:
                return 75;
            case self::STATUS_CODE_RESTORE_DATABASE_COMPLETED:
            case self::STATUS_CODE_RESTORE_DATABASE_ERRORS:
                return 90;
            case self::STATUS_CODE_CLEANUP_COMPLETE:
            case self::STATUS_CODE_CLEANUP_FAILED:
                return 100;
            default:
                return 100;
        }
    }
}