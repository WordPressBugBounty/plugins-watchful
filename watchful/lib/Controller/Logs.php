<?php

namespace Watchful\Controller;

use Watchful\Helpers\Logger;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * @package Watchful
 */
class Logs implements BaseControllerInterface
{
    private $logger;

    public function __construct()
    {
        $this->logger = new Logger();
    }

    public function register_routes()
    {
        register_rest_route(
            'watchful/v1',
            '/logs',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array($this, 'get_logs'),
                    'permission_callback' => array('Watchful\Routes', 'authentification'),
                ),
            )
        );
    }

    /**
     * @return WP_REST_Response
     */
    public function get_logs()
    {
        return new WP_REST_Response($this->logger->get_logs(), 200);
    }
}
