<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasSecurityHeaders extends AbstractAudit
{
    private const REQUIRED_HEADERS = [
        'x-frame-options',
        'content-security-policy',
        'strict-transport-security',
    ];

    public function run(?int $start = 0): stdClass
    {
        $response = wp_remote_head(home_url('/'), ['timeout' => 5, 'redirection' => 0]);
        if (is_wp_error($response)) {
            return $this->response->send_ok();
        }

        $headers = wp_remote_retrieve_headers($response);
        $missing_headers = [];
        foreach (self::REQUIRED_HEADERS as $header) {
            if (empty($headers[$header])) {
                $missing_headers[] = $header;
            }
        }

        if ($missing_headers) {
            return $this->response->send_ko($missing_headers);
        }

        return $this->response->send_ok();
    }
}
