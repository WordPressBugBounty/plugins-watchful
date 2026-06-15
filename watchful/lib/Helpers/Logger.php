<?php

namespace Watchful\Helpers;

use Exception;
use WP_Filesystem_Base;

final class Logger
{
    public const DEBUG = 100;
    public const INFO = 200;
    /**
     * Exceptional occurrences that are not errors
     *
     * Examples: Use of deprecated APIs, poor use of an API,
     * undesirable things that are not necessarily wrong.
     */
    public const WARNING = 300;
    public const ERROR = 400;

    /** @var string */
    private $log_dir;

    /** @var string */
    private $log_file;

    /** @var null | string */
    private $channel;
    private $max_file_size = 5242880;
    private $max_backup_files = 5;
    /** @var WP_Filesystem_Base */
    private $filesystem;

    public function __construct(?string $channel = null)
    {
        $this->log_dir = WATCHFUL_PLUGIN_CONTENT_DIR;
        $this->log_file = $this->log_dir . DIRECTORY_SEPARATOR . 'log.php';

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;
        $this->filesystem = $wp_filesystem;
        $this->channel = $channel;
    }

    public function get_logs(?int $lines = 0, ?string $channel = null): array
    {
        if (!$this->filesystem->exists($this->log_file)) {
            return [];
        }

        $content = $this->filesystem->get_contents_array($this->log_file);
        if (!$content) {
            return [];
        }

        $logs = array_slice($content, 2);

        if ($lines > 0) {
            $logs = array_slice($logs, -$lines);
        }

        $logs = array_values(
            array_filter($logs)
        );

        if (!empty($channel)) {
            $logs = array_values(array_filter($logs, function ($log) use ($channel) {
                $log = json_decode($log, true);

                return isset($log['channel']) && $log['channel'] === $channel;
            }));
        }

        $map = array_map(function ($log) {
            $log = json_decode($log, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            if (!isset($log['message'], $log['context'], $log['level'], $log['ts'])) {
                return null;
            }

            switch ($log['level']) {
                case self::DEBUG:
                    $log['level'] = 'DEBUG';
                    break;
                case self::INFO:
                    $log['level'] = 'INFO';
                    break;
                case self::WARNING:
                    $log['level'] = 'WARNING';
                    break;
                case self::ERROR:
                    $log['level'] = 'ERROR';
                    break;
                default:
                    $log['level'] = 'UNKNOWN';
                    break;
            }

            $log['ts'] = gmdate('Y-m-d H:i:s', $log['ts'] ?: 0);

            return $log;
        }, $logs);

        return array_values(
            array_filter($map)
        );
    }

    public function clear_logs(?string $channel = null): void
    {
        if (!$this->filesystem->exists($this->log_file)) {
            return;
        }

        $content = $this->filesystem->get_contents_array($this->log_file);
        if (!$content) {
            return;
        }

        $this->filesystem->put_contents($this->log_file, "<?php\nif (!defined('ABSPATH')) { exit; }\n", FS_CHMOD_FILE);

        if (empty($channel)) {
            return;
        }

        $logs = array_slice($content, 2);
        $logs = array_values(
            array_filter($logs)
        );

        $logs = array_values(array_filter($logs, function ($log) use ($channel) {
            $log = json_decode($log, true);

            return isset($log['channel']) && $log['channel'] === $channel;
        }));

        foreach ($logs as $log) {
            $log = json_decode($log, true);
            $this->log($log['message'], $log['context'], $log['level']);
        }
    }

    public function log($message, $context = [], $level = self::INFO)
    {
        try {
            if (!$this->filesystem->exists($this->log_dir)) {
                wp_mkdir_p($this->log_dir);
            }

            if (!$this->filesystem->exists($this->log_file)) {
                $this->initialize_log_file();
            }

            $this->check_log_size();

            $log_entry = [
                'message' => $message,
                'context' => $context,
                'level'   => $level,
                'ts'      => time(),
            ];

            if (!empty($this->channel)) {
                $log_entry['channel'] = $this->channel;
            }

            $encoded_entry = wp_json_encode($log_entry) . "\n";

            $existing = $this->filesystem->get_contents($this->log_file);

            return $this->filesystem->put_contents($this->log_file, ($existing ?: '') . $encoded_entry, FS_CHMOD_FILE);
        } catch (Exception $th) {
            return false;
        }
    }

    private function initialize_log_file()
    {
        $header = "<?php\nif (!defined('ABSPATH')) { exit; }\n";
        $this->filesystem->put_contents($this->log_file, $header, FS_CHMOD_FILE);
    }

    private function check_log_size()
    {
        if (
            !$this->filesystem->exists($this->log_file) ||
            $this->filesystem->size($this->log_file) <= $this->max_file_size
        ) {
            return;
        }

        $backup_file = str_replace('.php', '.' . current_time('Y-m-d-H-i-s') . '.php', $this->log_file);
        $this->filesystem->move($this->log_file, $backup_file);
        $this->initialize_log_file();
        $this->cleanup_old_logs();
    }

    private function cleanup_old_logs()
    {
        $log_path = dirname($this->log_file);
        $log_name = basename($this->log_file, '.php');
        $files = $this->filesystem->dirlist($log_path);

        $backup_files = [];
        foreach ($files as $file) {
            if (preg_match("/{$log_name}\.\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.php$/", $file['name'])) {
                $backup_files[$file['name']] = $file['lastmod'];
            }
        }

        if (count($backup_files) > $this->max_backup_files) {
            asort($backup_files);
            $files_to_delete = array_slice($backup_files, 0, count($backup_files) - $this->max_backup_files, true);

            foreach ($files_to_delete as $file => $time) {
                $this->filesystem->delete($log_path . '/' . $file);
            }
        }
    }

    public function error($message, $context = [])
    {
        return $this->log($message, $context, self::ERROR);
    }

    public function warning($message, $context = [])
    {
        return $this->log($message, $context, self::WARNING);
    }

    public function info($message, $context = [])
    {
        return $this->log($message, $context);
    }

    public function debug($message, $context = [])
    {
        return $this->log($message, $context, self::DEBUG);
    }
}