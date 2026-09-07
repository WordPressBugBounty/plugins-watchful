<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasExpiringCertificate extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        if (!function_exists('openssl_x509_parse')) {
            return $this->response->send_ok();
        }

        $url = wp_parse_url(home_url('/'));
        if (empty($url['host']) || 'https' !== ($url['scheme'] ?? '')) {
            return $this->response->send_ok();
        }

        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'peer_name' => $url['host']]]);
        $socket = @stream_socket_client('ssl://' . $url['host'] . ':' . ($url['port'] ?? 443), $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return $this->response->send_ok();
        }

        $params = stream_context_get_params($socket);
        $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        fclose($socket);

        if (is_array($certificate) && !empty($certificate['validTo_time_t']) && $certificate['validTo_time_t'] <= strtotime('+30 days')) {
            return $this->response->send_ko(gmdate('c', $certificate['validTo_time_t']));
        }

        return $this->response->send_ok();
    }
}
