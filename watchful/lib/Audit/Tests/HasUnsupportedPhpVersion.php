<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasUnsupportedPhpVersion extends AbstractAudit
{
    private const MINIMUM_SUPPORTED_VERSION = '8.3.0';

    public function run(?int $start = 0): stdClass
    {
        return $this->check_value(PHP_VERSION, self::MINIMUM_SUPPORTED_VERSION, '>=');
    }
}
