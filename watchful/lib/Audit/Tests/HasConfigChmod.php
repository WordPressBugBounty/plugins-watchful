<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasConfigChmod extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $last = '0';
        $file_name = 'wp-config.php';

        $file = file_exists(ABSPATH.$file_name) ? ABSPATH.$file_name : ABSPATH.'/../'.$file_name;

        $mode = substr(sprintf('%o', fileperms($file)), -4);

        if (substr($mode, -1) === $last) {
            return $this->response->send_ok();
        }

        return $this->response->send_ko($mode);
    }
}
