<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasDbWeakPassword extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $password = DB_PASSWORD;
        if (!$password || $this->is_password_weak($password)) {
            return $this->response->send_ko($password);
        }

        return $this->response->send_ok();
    }

    private function is_password_weak(string $db_password): bool
    {
        if (in_array($db_password, $this->passwords, true)) {
            return true;
        }

        return false;
    }
}
