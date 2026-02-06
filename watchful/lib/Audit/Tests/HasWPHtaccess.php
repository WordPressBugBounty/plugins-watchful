<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasWPHtaccess extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $file = '.htaccess';
        $server_name = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(
            wp_unslash($_SERVER['SERVER_SOFTWARE'])
        ) : '';
        if (preg_match('#IIS/([\d.]*)#', $server_name)) {
            $file = 'web.config'; // IIS.
        }

        if (!file_exists(ABSPATH.'/'.$file)) {
            return $this->response->send_ko();
        }

        return $this->response->send_ok();
    }
}
