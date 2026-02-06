<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasBadKeys extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $keys = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT',
        ];
        $bad_keys = $this->get_bad_keys($keys);

        if (count($bad_keys)) {
            return $this->response->send_ko($bad_keys);
        }

        return $this->response->send_ok();
    }

    /**
     * Get all invalid keys
     *
     * @param array $keys The keys.
     *
     * @return array
     */
    private function get_bad_keys($keys)
    {
        $bad_keys = [];

        foreach ($keys as $key) {
            if (!defined($key)) {
                continue;
            }
            $constant = constant($key);

            if (empty($constant) || trim($constant) === 'put your unique phrase here' || strlen($constant) < 32) {
                $t = new stdClass();
                $t->key = $key;
                $t->value = base64_encode($constant);
                $t->encoder = 'base64';

                $bad_keys[] = $t;
            }
        }

        return $bad_keys;
    }
}
