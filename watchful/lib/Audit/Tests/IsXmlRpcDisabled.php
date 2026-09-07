<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsXmlRpcDisabled extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        return $this->check_value(apply_filters('xmlrpc_enabled', true), false);
    }
}
