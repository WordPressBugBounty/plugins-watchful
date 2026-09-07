<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasRootArchives extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $archives = array_merge(glob(ABSPATH . '*.{zip,sql}', GLOB_BRACE) ?: [], glob(ABSPATH . '*.{ZIP,SQL}', GLOB_BRACE) ?: []);
        $archives = array_values(array_unique(array_map('basename', $archives)));

        if ($archives) {
            return $this->response->send_ko($archives);
        }

        return $this->response->send_ok();
    }
}
