<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasWPAdminUser extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        return $this->check_value(username_exists('admin'), 0);
    }
}
