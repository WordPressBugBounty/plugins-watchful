<?php

namespace Watchful\Helpers\BackupPlugins;

use Watchful\Backup\Processor;
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
     * @return array | WP_Error
     */
    public function start_backup()
    {
        return $this->processor->start_backup();
    }

    /**
     * @param string $backup_id
     * @return array | false
     */
    public function step_backup(array $params)
    {
        return $this->processor->step_backup($params);
    }
}