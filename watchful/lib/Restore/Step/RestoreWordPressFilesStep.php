<?php

namespace Watchful\Restore\Step;

use Watchful\Restore\StepResponse;

class RestoreWordPressFilesStep extends BaseRestoreFilesStep
{
    protected function get_extract_filename(): string
    {
        return 'wordpress_core_files_to_extract.json';
    }

    protected function get_delete_filename(): string
    {
        return 'wordpress_core_files_to_delete.json';
    }

    protected function get_log_name(): string
    {
        return 'wordpress files';
    }

    protected function get_error_status_code(): string
    {
        return StepResponse::STATUS_CODE_RESTORE_WORDPRESS_FILES_ERRORS;
    }

    protected function get_completed_status_code(): string
    {
        return StepResponse::STATUS_CODE_RESTORE_WORDPRESS_FILES_COMPLETED;
    }

    protected function get_partial_status_code(): string
    {
        return StepResponse::STATUS_CODE_RESTORE_WORDPRESS_FILES_PARTIAL;
    }
}
