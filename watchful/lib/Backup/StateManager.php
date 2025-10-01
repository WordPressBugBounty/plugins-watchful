<?php

namespace Watchful\Backup;

use RuntimeException;
use Watchful\Helpers\Logger;
use Watchful\Model\BackupState;

final class StateManager
{
    /** @var Utils */
    private $utils;
    /** @var Logger */
    private $logger;

    public function __construct()
    {
        $this->utils = new Utils();
        $this->logger = new Logger('backup');
    }

    /**
     * @throws RuntimeException
     */
    public function create_new_backup(
        string $backup_id,
        string $backup_type = Utils::BACKUP_TYPE_FULL,
        ?string $full_backup_id = null
    ): BackupState {
        $backup_state = new BackupState();
        $backup_state->status = 'in_progress';
        $backup_state->errors = [];

        $backup_state->manifest->id = $backup_id;
        $backup_state->manifest->backup_type = $backup_type;
        $backup_state->manifest->full_backup_id = $full_backup_id;
        $backup_state->manifest->completed_at = null;
        $backup_state->manifest->created_at = current_time('timestamp');
        $backup_state->manifest->plugin_version = WATCHFUL_VERSION;
        $backup_state->manifest->cms_version = get_bloginfo('version');
        $backup_state->manifest->cms_abs_path = ABSPATH;
        $backup_state->manifest->php_version = phpversion();
        $backup_state->manifest->site_url = get_site_url();

        $this->store_state($backup_state);

        return $backup_state;
    }

    /**
     * @throws RuntimeException
     */
    public function store_state(BackupState $backup_state): void
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $backup_id = $backup_state->manifest->id;
        if (empty($backup_id)) {
            throw new RuntimeException('Backup ID is required to store state');
        }

        $state_path = $this->get_state_path($backup_id);
        $result = $wp_filesystem->put_contents(
            $state_path,
            wp_json_encode($backup_state),
            FS_CHMOD_FILE
        );

        $this->logger->debug('Storing backup state', [
            'backup_id' => $backup_id,
            'state_path' => $state_path,
            'result' => $result,
            'backup_state' => $backup_state->jsonSerialize(),
        ]);

        if ($result === false) {
            throw new RuntimeException('Failed to store state');
        }
    }

    private function get_state_path(string $backup_id): string
    {
        $backup_dir = $this->utils->get_backup_directory($backup_id);

        return $backup_dir.DIRECTORY_SEPARATOR.'state.json';
    }

    public function get_previous_backup_file_list(string $backup_id): array
    {
        $cache_key = 'watchful_previous_backup_file_list_'.$backup_id;
        $cached_data = wp_cache_get($cache_key, 'watchful_backup');

        if ($cached_data !== false) {
            return $cached_data;
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $file_list_path = $this->get_previous_backup_file_list_path($backup_id);
        if (!$wp_filesystem->exists($file_list_path)) {
            return [];
        }

        $data = $wp_filesystem->get_contents($file_list_path);
        if ($data === false) {
            throw new RuntimeException('Failed to read previous backup file list');
        }

        $result = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in previous backup file list: '.json_last_error_msg());
        }

        if (!is_array($result) || empty($result)) {
            throw new RuntimeException('Previous backup file list is not an array');
        }

        wp_cache_set($cache_key, $result, 'watchful_backup', 3600);

        return $result;
    }

    private function get_previous_backup_file_list_path(string $backup_id): string
    {
        $backup_dir = $this->utils->get_backup_directory($backup_id);

        return $backup_dir.DIRECTORY_SEPARATOR.'previous_file_list.json';
    }

    public function store_previous_backup_file_list(string $backup_id, array $file_list): void
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH.'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        $file_list_path = $this->get_previous_backup_file_list_path($backup_id);
        $result = $wp_filesystem->put_contents(
            $file_list_path,
            wp_json_encode($file_list),
            FS_CHMOD_FILE
        );

        if ($result === false) {
            throw new RuntimeException('Failed to store previous backup file list');
        }
    }

    /**
     * @throws RuntimeException
     */
    public function get_state(string $backup_id): BackupState
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

        $data = json_decode($wp_filesystem->get_contents($state_path), true);

        return BackupState::fromArray($data);
    }
}