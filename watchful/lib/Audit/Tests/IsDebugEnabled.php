<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsDebugEnabled extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return $this->response->send_ko();
        }

        return $this->response->send_ok();
    }
}
