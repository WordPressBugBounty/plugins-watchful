<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasReadme extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $response = wp_remote_head(home_url('/readme.html'), ['timeout' => 5, 'redirection' => 0]);
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            return $this->response->send_ko('readme.html');
        }

        return $this->response->send_ok();
    }
}
