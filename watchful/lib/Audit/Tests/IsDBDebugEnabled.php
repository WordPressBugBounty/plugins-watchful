<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsDBDebugEnabled extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        global $wpdb;

        return $this->check_value($wpdb->show_errors, 0);
    }
}
