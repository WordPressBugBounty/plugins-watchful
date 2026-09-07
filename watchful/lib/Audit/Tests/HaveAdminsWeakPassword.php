<?php
/**
 * Watchful admin brute force test.
 *
 * @version     2016-12-20 11:41 UTC+01
 * @package     Watchful WP Client
 * @author      Watchful
 * @authorUrl   https://watchful.net
 * @copyright   Copyright (c) 2020 watchful.net
 * @license     GNU/GPL
 */

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;
use Watchful\Exception;

class HaveAdminsWeakPassword extends AbstractAudit
{
    public function run(?int $start = null): stdClass
    {
        $this->logger->log('Checking for weak passwords', ['start' => $start ?? 0]);

        try {
            $weak_admins = $this->check_passwords($start);
        } catch (Exception $e) {
            $data = $e->getData();

            $this->logger->warning('Time limit reached, sending timeout response.', ['data' => $data ?? '']);

            $admin_index = $data['admin_index'];
            $password_index = $data['password_index'];

            $start = $admin_index * count($this->passwords) + $password_index;

            return $this->response->send_timeout($start);
        }

        $this->logger->log('Done checking for weak passwords.');

        if (count($weak_admins)) {
            return $this->response->send_ko($weak_admins);
        }

        return $this->response->send_ok();
    }

    /**
     * @throws Exception
     */
    private function check_passwords(?int $start): array
    {
        $this->logger->log('Getting admins', ['start' => $start ?? 0]);

        $admins = get_users(['role' => 'administrator']);

        $weak_admins = [];
        $passwords_count = count($this->passwords);
        if ($passwords_count === 0) {
            return $weak_admins;
        }

        $start = (int) $start;
        $start_admin_index = intdiv($start, $passwords_count);
        $start_password_index = $start % $passwords_count;

        $this->logger->log(
            'Checking passwords',
            [
                'start_admin_index'    => $start_admin_index,
                'start_password_index' => $start_password_index,
                'admins_count'         => count($admins),
            ]
        );

        foreach ($admins as $admin_index => $admin) {
            if ($admin_index < $start_admin_index) {
                continue;
            }

            $this->logger->log('Checking password for admin', [
                'admin_index' => $admin_index,
            ]);

            foreach ($this->passwords as $password_index => $password) {
                if ($admin_index === $start_admin_index && $password_index < $start_password_index) {
                    continue;
                }

                if ($this->have_time() === false) {
                    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ExceptionHandler serialises this as JSON, not HTML.
                    throw new Exception('Time limit reached', 408, [
                        'admin_index'    => $admin_index,
                        'password_index' => $password_index,
                    ]);
                    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                if (wp_check_password($password, $admin->data->user_pass) === false) {
                    continue;
                }

                $t = new stdClass();
                $t->login = $admin->data->user_login;
                $t->password = $password;

                $weak_admins[] = $t;
            }
        }

        return $weak_admins;
    }
}
