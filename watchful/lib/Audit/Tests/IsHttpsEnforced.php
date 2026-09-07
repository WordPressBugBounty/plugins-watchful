<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsHttpsEnforced extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $url = home_url('/');
        if (stripos($url, 'https://') !== 0) {
            return $this->response->send_ko($url);
        }

        return $this->response->send_ok();
    }
}
