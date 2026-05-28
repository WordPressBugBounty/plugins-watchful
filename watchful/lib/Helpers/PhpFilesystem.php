<?php

namespace Watchful\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around native PHP filesystem functions.
 *
 * Centralises all direct PHP filesystem calls so that each usage can be
 * audited and optionally replaced with WP_Filesystem equivalents in a
 * single place, rather than scattered across the codebase.
 *
 * The phpcs:ignore annotations live here, not in the callers.
 */
class PhpFilesystem
{
    /**
     * @param string $filename
     * @param string $mode
     * @return resource|false
     */
    public static function fopen(string $filename, string $mode)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        return fopen($filename, $mode);
    }

    /**
     * @param resource $handle
     * @return bool
     */
    public static function fclose($handle): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return fclose($handle);
    }

    /**
     * @param resource $handle
     * @param int      $length
     * @return string|false
     */
    public static function fread($handle, int $length)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        return fread($handle, $length);
    }

    /**
     * @param resource $handle
     * @param string   $data
     * @return int|false
     */
    public static function fwrite($handle, string $data)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        return fwrite($handle, $data);
    }

    /**
     * @param string $path
     * @param int    $permissions
     * @param bool   $recursive
     * @return bool
     */
    public static function mkdir(string $path, int $permissions = 0755, bool $recursive = false): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        return mkdir($path, $permissions, $recursive);
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function is_writable(string $path): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        return is_writable($path);
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function unlink(string $path): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return unlink($path);
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function rmdir(string $path): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        return rmdir($path);
    }

    /**
     * @param string $path
     * @param int    $permissions
     * @return bool
     */
    public static function chmod(string $path, int $permissions): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        return chmod($path, $permissions);
    }

    /**
     * @param string   $filename
     * @param mixed    $data
     * @param int      $flags
     * @return int|false
     */
    public static function file_put_contents(string $filename, $data, int $flags = 0)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return file_put_contents($filename, $data, $flags);
    }
}
