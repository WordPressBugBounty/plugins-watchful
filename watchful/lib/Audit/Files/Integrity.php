<?php

namespace Watchful\Audit\Files;

use Exception;
use stdClass;
use Watchful\Audit\AbstractAudit;
use Watchful\Helpers\Connection;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Integrity extends AbstractAudit
{
    /**
     * Compare the hashes of the core files
     * @throws Exception
     */
    public function run(?int $start = 0): stdClass
    {
        $connection = new Connection();
        $data = $connection->get_hash();
        $current = $start;

        $result = new stdClass();
        $result->wrong = array(); // Files with wrong checksums.
        $result->missing = array(); // Files missing.
        $result->skipped = array(); // Files skipped.
        $result->size = count($data);
        $result->start = $start;

        $data_count = count($data);
        while ($this->have_time() && $current < $data_count) {
            $file_path = $data[$current][0];
            $file_hash = $data[$current][1];
            $full_path = str_replace('wordpress/', ABSPATH, $data[$current][0]);

            $status = $this->check_integrity_file($full_path, $file_hash, $this->get_memory_limit_in_bytes());

            if ('ok' !== $status) {
                $result->$status[] = preg_replace('#^wordpress/#', '/', $file_path);
            }
            $current++;
        }

        $result->lastFileChecked = $file_path; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        $result->end = $current;

        return $result;
    }

    /**
     * Compare the md5 hash of a file with a reference.
     */
    private function check_integrity_file(string $full_path, string $file_hash, int $memory_limit): string
    {
        if (!file_exists($full_path)) {
            return 'missing';
        }

        $memory_usage = memory_get_usage();
        $file_size = filesize($full_path);

        // Let's hope the file can be read.
        $memory_needed = $memory_usage + $file_size;
        if ($memory_needed > $memory_limit) {
            return 'skipped';
        }

        // Does this file have a wrong checksum?
        if (md5_file($full_path) !== $file_hash) {
            return 'wrong';
        }

        return 'ok';
    }

    /**
     * Get the memory limit in bytes.
     *
     * @return int
     */
    private function get_memory_limit_in_bytes()
    {
        $memory_limit = ini_get('memory_limit');
        switch (substr($memory_limit, -1)) {
            case 'K':
                $memory_limit = (int)$memory_limit * 1024;
                break;

            case 'M':
                $memory_limit = (int)$memory_limit * 1024 * 1024;
                break;

            case 'G':
                $memory_limit = (int)$memory_limit * 1024 * 1024 * 1024;
                break;
        }

        return $memory_limit;
    }
}
