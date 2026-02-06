<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsUploadBrowsable extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $upload_dir = wp_upload_dir();

        $args = array(
            'method' => 'GET',
            'timeout' => 5,
            'redirection' => 0,
            'httpversion' => 1.0,
            'blocking' => true,
            'headers' => array(),
            'body' => null,
            'cookies' => array(),
        );

        $response = wp_remote_get(rtrim($upload_dir['baseurl'], '/').'/?nocache='.wp_rand(), $args);
        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        if ('200' === $code && stripos($body, 'index') !== false) {
            return $this->response->send_ko($upload_dir['baseurl'].'/');
        }

        return $this->response->send_ok();
    }
}
