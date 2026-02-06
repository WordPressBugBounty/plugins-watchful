<?php

namespace Watchful\Restore\Step;

use Watchful\Restore\StepResponse;

class RestoreUserFilesStep extends BaseRestoreFilesStep
{
    protected function get_extract_filename(): string
    {
        return 'user_files_to_extract.json';
    }

    protected function get_delete_filename(): string
    {
        return 'user_files_to_delete.json';
    }

    protected function get_log_name(): string
    {
        return 'user files';
    }

    protected function get_error_status_code(): string
    {
        return StepResponse::STATUS_CODE_RESTORE_USER_FILES_ERRORS;
    }

    protected function get_completed_status_code(): string
    {
        return StepResponse::STATUS_CODE_RESTORE_USER_FILES_COMPLETED;
    }

    protected function get_partial_status_code(): string
    {
        return StepResponse::STATUS_CODE_RESTORE_USER_FILES_PARTIAL;
    }
}
