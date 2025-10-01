<?php

namespace Watchful\Controller;

use Watchful\Helpers\Authentification;
use Watchful\Restore\Processor;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Restore implements BaseControllerInterface
{
    private $restore_manager;

    public function __construct()
    {
        $this->restore_manager = new Processor();
    }

    public function step_restore(WP_REST_Request $request): WP_REST_Response
    {
        $request = json_decode($request->get_body(), true);

        return new WP_REST_Response($this->restore_manager->step_restore($request));
    }


    public function register_routes()
    {
        register_rest_route(
            'watchful/v1',
            '/restore/step',
            array(
                'methods' => array(WP_REST_Server::CREATABLE),
                'callback' => array($this, 'step_restore'),
                'permission_callback' => array('Watchful\Routes', 'authentification'),
                'args' => Authentification::get_arguments(),
            )
        );
    }
}
