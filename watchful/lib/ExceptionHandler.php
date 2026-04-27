<?php
/**
 * Handle Watchful exceptions.
 *
 * @version     2016-12-20 11:41 UTC+01
 * @package     watchful
 * @author      Watchful
 * @authorUrl   https://watchful.net
 * @copyright   Copyright (c) 2020 watchful.net
 * @license     GNU/GPL
 */

namespace Watchful;

use Error;
use Throwable;

/**
 * Class for handling Watchful exceptions.
 */
class ExceptionHandler
{
    public function __construct()
    {
        set_exception_handler([$this, 'exception']);
    }

    /**
     * Handle the exception.
     *
     * @param   \Exception|Throwable|Error  $exception  The exception to handle.
     */
    public function exception($exception)
    {
        $response = [
            'error' => 1,
            'trace' => $exception->getTraceAsString(),
        ];

        if ($exception instanceof \Exception || $exception instanceof Error) {
            $response = [
                'error'   => 1,
                'code'    => $exception->getCode(),
                'message' => $exception->getMessage(),
            ];

            if ($this->should_include_trace()) {
                $response['details'] = json_encode(
                    [
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    ]
                );
                $response['trace']   = $exception->getTraceAsString();
            }
        }

        // Check for instance of `\Watchful\Exception`.
        if ($exception instanceof Exception && !is_null($exception->getData())) {
            $response['data'] = $exception->getData();
        }

        if (function_exists('http_response_code') && $exception instanceof \Exception) {
            http_response_code($exception->getCode());
        }
        echo wp_json_encode($response);
        die();
    }

    /**
     * Standard WP methods to detect logged in user are not available.
     * We'll have to define a constant somewhere.
     *
     * @return bool
     */
    private function should_include_trace(): bool
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }

        if (defined('WATCHFUL_AUTENTICATED') && WATCHFUL_AUTENTICATED) {
            return true;
        }

        return false;
    }
}
