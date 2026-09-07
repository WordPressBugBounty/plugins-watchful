<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasWritablePhpFiles extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $files = glob(ABSPATH . '*.php') ?: [];
        $writable_files = [];

        foreach ($files as $file) {
            if (is_writable($file)) {
                $writable_files[] = basename($file);
            }
        }

        if ($writable_files) {
            return $this->response->send_ko($writable_files);
        }

        return $this->response->send_ok();
    }
}
