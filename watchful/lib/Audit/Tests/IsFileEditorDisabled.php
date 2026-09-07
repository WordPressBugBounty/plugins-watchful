<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class IsFileEditorDisabled extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        return $this->check_value(defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT, true);
    }
}
