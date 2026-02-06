<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;
use Watchful\Helpers\Connection;

class RobotsTxt extends AbstractAudit
{
    /**
     * Run the test.
     *
     * @return mixed
     */
    public function run(?int $start = 0): stdClass
    {
        $signatures = $this->load_signatures();

        $file_path = ABSPATH.'/robots.txt';

        if (!file_exists($file_path)) {
            return $this->response->send_ok();
        }

        $matches = [];
        $temp_matches = [];

        $content = implode('', file($file_path, FILE_IGNORE_NEW_LINES));

        foreach ($signatures as $signature) {
            if ('regex.wp-robotstxt' !== $signature->type) {
                continue;
            }

            if (preg_match_all($signature->signature, $content, $temp_matches)) {
                $matches = array_merge($matches, $temp_matches[0]);
            }
        }

        if (empty($matches)) {
            return $this->response->send_ok();
        }

        return $this->response->send_ko($matches);
    }

    private function load_signatures()
    {
        $signatures = get_transient('signatures');

        if (false === $signatures) {
            $connection = new Connection();
            $signatures = $connection->get_signatures();

            set_transient('signatures', $signatures, 6 * 3600);
        }

        return $signatures;
    }
}
