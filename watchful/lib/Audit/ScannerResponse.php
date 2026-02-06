<?php
/**
 * Watchful scanner response.
 *
 * @version     2016-12-20 11:41 UTC+01
 * @package     Watchful WP Client
 * @author      Watchful
 * @authorUrl   https://watchful.net
 * @copyright   Copyright (c) 2020 watchful.net
 * @license     GNU/GPL
 */

namespace Watchful\Audit;

use stdClass;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ScannerResponse
{
    public const TIMEOUT_ERROR_CODE = 999;

    /**
     * Send a positive response with no errors.
     *
     * @param mixed $value The value for the response.
     */
    public function send_ok($value = null): stdClass
    {
        return $this->get_results($value, 0);
    }

    /**
     * Get the response.
     *
     * @param mixed $values The values for the response.
     */
    private function get_results($values, int $error): stdClass
    {
        $rep = new stdClass();
        $rep->error = $error;
        $rep->values = $values;

        return $rep;
    }

    /**
     * Send a negative response with an error code of 1.
     *
     * @param mixed $value The value for the response.
     */
    public function send_ko($value = null): stdClass
    {
        return $this->get_results($value, 1);
    }

    /**
     * Send an unknown response.
     *
     * @param mixed $value The value for the response.
     */
    public function send_timeout($value = null): stdClass
    {
        return $this->get_results($value, self::TIMEOUT_ERROR_CODE);
    }
}
