<?php

namespace Watchful\Backup;

use RuntimeException;

final class StateManager
{
    /** @var Utils */
    private $utils;

    public function __construct()
    {
        $this->utils = new Utils();
    }

    /**
     * @throws RuntimeException
     */
    public function create_new_backup(string $backup_id): array
    {
        $backup_state = [
            'id' => $backup_id,
            'status' => 'in_progress',
            'steps' => [
                'file_enumeration' => [
                    'completed' => false,
                    'current_offset' => 0,
                    'size' => 0,
                ],
                'database' => [
                    'completed' => false,
                    'total_rows' => 0,
                    'rows_processed' => 0,
                    'current_table' => null,
                    'current_offset' => 0,
                ],
                'archive' => [
                    'completed' => false,
                    'current_offset' => 0,
                    'size_processed' => 0,
                ],
                'upload' => [
                    'completed' => false,
                    'total_size' => 0,
                    'total_parts' => 0,
                    'current_offset' => 0,
                    'part_number' => 0,
                    'parts' => [],
                ],
                'verification' => [
                    'completed' => false,
                ],
                'cleanup' => [
                    'completed' => false,
                ],
            ],
            'errors' => [],
            'created_at' => current_time('mysql'),
            'completed_at' => null,
        ];

        $this->store_state($backup_state);

        return $backup_state;
    }

    /**
     * @throws RuntimeException
     */
    public function store_state(array $backup_state): void
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_id = $backup_state['id'];
        if (empty($backup_id)) {
            throw new RuntimeException('Backup ID is required to store state');
        }

        $state_path = $this->get_state_path($backup_state['id']);
        $result = $wp_filesystem->put_contents($state_path, wp_json_encode($backup_state), FS_CHMOD_FILE);

        if ($result === false) {
            throw new RuntimeException('Failed to store state');
        }
    }

    private function get_state_path(string $backup_id): string
    {
        $backup_dir = $this->utils->get_backup_directory($backup_id);

        return $backup_dir.DIRECTORY_SEPARATOR.'state.json';
    }

    /**
     * @throws RuntimeException
     */
    public function get_state(string $backup_id): array
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $state_path = $this->get_state_path($backup_id);
        if (!$wp_filesystem->exists($state_path)) {
            throw new RuntimeException('State file not found');
        }

        return json_decode($wp_filesystem->get_contents($state_path), true);
    }
}