<?php
/**
 * Watchful auditor.
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
use Watchful\Helpers\Logger;
use function WP_Filesystem;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

abstract class AbstractAudit
{
    /**
     * @var int
     */
    public const _DEFAULT_TIMEEXECUTION_LIMIT = 10;

    /**
     * List of passwords.
     *
     * @var array
     */
    protected $passwords;

    /** @var ScannerResponse */
    protected $response;

    protected $logger;

    /**
     * Maximum execution time.
     *
     * @var int
     */
    private $max_execution_time;

    /**
     * Start time.
     *
     * @var int
     */
    private $start_time;

    public function __construct(?float $start = null, ?int $max_execution_time = null)
    {
        $this->response = new ScannerResponse();

        $this->logger = new Logger('audit');

        $this->load_passwords();

        $this->start_time         = $start ?? microtime(true);
        $this->max_execution_time = $this->calculate_max_execution_time($max_execution_time);
    }

    /**
     * Load a list of password, with cache
     */
    private function load_passwords()
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;
        $passwords = $wp_filesystem->get_contents(WATCHFUL_PLUGIN_DIR . 'lib/Audit/Resources/weak_passwords.txt');

        if (!empty($passwords)) {
            $passwords = preg_split("/(\r\n|\n|\r)/", $passwords);
        }
        $this->passwords = $passwords;
    }

    private function calculate_max_execution_time(?int $max_execution_time = null): int
    {
        if ($max_execution_time) {
            $this->logger->debug('Max execution time set by master', ['time' => $max_execution_time]);

            return $max_execution_time;
        }

        $php_execution_time = (int)ini_get('max_execution_time');

        if (!$php_execution_time) {
            $this->logger->debug('Max execution time not set in PHP config');

            return self::_DEFAULT_TIMEEXECUTION_LIMIT;
        }

        $this->logger->debug('Max execution time set in PHP config', ['time' => $php_execution_time]);

        // Return 3 seconds less than the `max_execution_time` in the server PHP config.
        return $php_execution_time - 3;
    }

    /**
     * Compare two values and return the correct status
     *
     * @param mixed $value The given value to check.
     * @param mixed $expected_value The expected value to compare against.
     * @param string $comparaison The compareison enumeration: ==,<,>,<=,>=.
     *
     * @return stdClass
     */
    public function check_value($value, $expected_value, $comparaison = '==')
    {
        $map = array(
            '>=' => $value >= $expected_value,
            '>' => $value > $expected_value,
            '<=' => $value <= $expected_value,
            '<' => $value < $expected_value,
            '==' => $value == $expected_value, // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
            '!=' => $value != $expected_value, // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
        );

        if ($map[$comparaison]) {
            return $this->response->send_ok($value);
        }

        return $this->response->send_ko($value);
    }

    abstract public function run(?int $start = null);

    /**
     * Calculate if we have 1 second left
     */
    public function have_time(): bool
    {
        $available_time = $this->max_execution_time - $this->have_run();

        return $available_time > 1;
    }

    /**
     * Number of seconds from the start of the script
     */
    private function have_run()
    {
        return microtime(true) - $this->start_time;
    }

    protected function is_windows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
}
