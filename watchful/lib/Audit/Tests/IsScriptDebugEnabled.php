<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsScriptDebugEnabled extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) {
            return $this->response->send_ko();
        }

        return $this->response->send_ok();
    }
}
