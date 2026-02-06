<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;
use WP_Http;

class HasPhpVersion extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $headers = $this->get_headers(home_url());
        $bad_headers = array();

        foreach ($headers as $key => $header) {
            if (is_array($header)) {
                $header = implode(', ', $header);
            }
            if (
                ('server' === $key || 'x-powered-by' === $key) &&
                stripos($header, phpversion()) !== false
            ) {
                $bad_headers[$key] = $header;
            }
        }

        if (!empty($bad_headers)) {
            return $this->response->send_ko($bad_headers);
        }

        return $this->response->send_ok();
    }

    /**
     * Get the http header from the given url
     * @return mixed
     */
    private function get_headers(string $url)
    {
        if (!class_exists('WP_Http')) {
            require ABSPATH.WPINC.'/class-http.php';
        }

        $http = new WP_Http();
        $response = (array)$http->request($url);

        return wp_remote_retrieve_headers($response);
    }
}
