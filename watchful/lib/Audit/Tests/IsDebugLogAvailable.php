<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsDebugLogAvailable extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        if (file_exists($this->getLogPath())) {
            return $this->response->send_ko();
        }

        return $this->response->send_ok();
    }

    private function getLogPath()
    {
        if (in_array(strtolower((string)WP_DEBUG_LOG), array('true', '1'), true)) {
            return WP_CONTENT_DIR.'/debug.log';
        }

        if (is_string(WP_DEBUG_LOG) && !in_array(strtolower(WP_DEBUG_LOG), array('false', '0'), true)) {
            return WP_DEBUG_LOG;
        }

        return WP_CONTENT_DIR.'/debug.log';
    }
}
