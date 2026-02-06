<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasDBPrefix extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $prefix = ['wp', 'wordpress', 'wp3'];

        global $wpdb;

        // Remove the "_".
        $current = substr($wpdb->prefix, 0, -1);

        if (!in_array($current, $prefix, true)) {
            return $this->response->send_ok();
        }

        return $this->response->send_ko($current);
    }
}
