<?php

namespace Watchful\Helpers\BackupPlugins;

use Watchful\Backup\Processor;
use Watchful\Model\BackupState;
use WP_Error;

class WatchfulBackupPlugin implements BackupPluginInterface
{
    /** @var Processor */
    private $processor;

    public function __construct()
    {
        $this->processor = new Processor();
    }

    public function get_last_backup_date()
    {
        return false;
    }

    public function get_backup_list()
    {
        return array();
    }

    /**
     * Start backup with differential support
     * @param array $options Options including backup_type and base_backup_files
     * @return array | WP_Error
     */
    public function start_backup_with_options(array $options = [])
    {
        return $this->processor->start_backup_with_options($options);
    }

    /**
     * @return BackupState | WP_Error
     */
    public function step_backup(array $params)
    {
        return $this->processor->step_backup($params);
    }
}