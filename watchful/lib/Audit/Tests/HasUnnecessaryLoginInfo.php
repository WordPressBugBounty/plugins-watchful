<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;
use WP_Http;

class HasUnnecessaryLoginInfo extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $params = [
            'log' => 'sn-test_3453344355',
            'pwd' => 'sn-test_2344323335',
        ];
        $url = get_bloginfo('wpurl').'/wp-login.php';

        $body = $this->get_login_body($url, $params);

        if (stristr($body, 'invalid username') !== false) {
            return $this->response->send_ko();
        }

        return $this->response->send_ok();
    }
    
    private function get_login_body(string $url, array $user): string
    {
        if (!class_exists('WP_Http')) {
            require ABSPATH.WPINC.'/class-http.php';
        }

        $http = new WP_Http();
        $response = (array)$http->request(
            $url,
            [
                'method' => 'POST',
                'body' => $user,
            ]
        );

        return wp_remote_retrieve_body($response);
    }
}
