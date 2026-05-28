<?php

namespace Watchful\Helpers;

final class LockFactory
{
    private const LOCK_PREFIX = 'watchful_lock_';
    private $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }


    public function acquire(string $lock_name, int $timeout = 20): bool
    {
        $lock_key = self::LOCK_PREFIX.$lock_name;

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no WP abstraction exists and result must never be cached.
        $result = $wpdb->query($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_key, $timeout));

        if ($result) {
            $this->logger->log("Lock acquired", ['lock_name' => $lock_name]);

            return true;
        }

        $this->logger->log("Failed to acquire lock", ['lock_name' => $lock_name]);

        return false;
    }

    public function release(string $lock_name): bool
    {
        $lock_key = self::LOCK_PREFIX.$lock_name;

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no WP abstraction exists and result must never be cached.
        $result = $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));

        if ($result) {
            $this->logger->log("Lock released", ['lock_name' => $lock_name]);

            return true;
        }

        $this->logger->log("Failed to release lock", ['lock_name' => $lock_name]);

        return false;
    }
}