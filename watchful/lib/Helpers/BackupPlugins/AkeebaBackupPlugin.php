<?php

namespace Watchful\Helpers\BackupPlugins;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use Akeeba\Engine\Factory;
use DateTime;
use Exception;
use Watchful\Helpers\BackupPluginHelper;

class AkeebaBackupPlugin implements BackupPluginInterface
{
    /**
     * Get Akeeba Secret Key for remote backup
     *
     * @return string|null
     */
    public static function get_akeeba_secret_key()
    {
        global $akeebaBackupWordPressContainer;
        if (!BackupPluginHelper::has_active_backup_plugin('akeeba')) {
            return '';
        }
        try {
            if (!defined('AKEEBASOLO')) {
                define('AKEEBASOLO', 1);
            }

            if (!file_exists(WP_PLUGIN_DIR . '/akeebabackupwp/index.php')) {
                return null;
            }
            if (!class_exists('Awf\\Autoloader\\Autoloader')) {
                include_once WP_PLUGIN_DIR . '/akeebabackupwp/app/Awf/Autoloader/Autoloader.php';
            }
            include_once WP_PLUGIN_DIR . '/akeebabackupwp/helpers/integration.php';
            if (!defined('AKEEBASOLO')) {
                define('AKEEBAENGINE', 1);
            }
            if (empty($akeebaBackupWordPressContainer)) {
                return null;
            }
            $akeebaBackupWordPressContainer->appConfig->loadConfiguration();

            if ($akeebaBackupWordPressContainer->appConfig->get('options.frontend_enable' !== 1)) {
                return null;
            }
            if (!class_exists('Akeeba\\Engine\\Factory')) {
                include_once WP_PLUGIN_DIR . '/akeebabackupwp/app/Solo/engine/Factory.php';
            }
            if (file_exists(WP_PLUGIN_DIR . '/akeebabackupwp/app/Solo/engine/secretkey.php')) {
                include_once WP_PLUGIN_DIR . '/akeebabackupwp/app/Solo/engine/secretkey.php';
            }
            $secret_word = $akeebaBackupWordPressContainer->appConfig->get('options.frontend_secret_word');
            if (class_exists(Factory::class)) {
                return Factory::getSecureSettings()->decryptSettings($secret_word, null);
            }

            return $secret_word;
        } catch (Exception $exception) {
            return '';
        }
    }

    /**
     * @param null|string $profile_id
     * @return DateTime | false
     */
    public function get_last_backup_date($profile_id = null)
    {
        global $wpdb;
        $cache_key = '_watchful_latest_backup_info';

        if ($profile_id !== null) {
            $cache_key .= '_' . $profile_id;
        }

        $backup_info = wp_cache_get($cache_key);

        $table_name = $wpdb->prefix . 'ak_stats';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
            return false;
        }

        if (false === $backup_info) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $safe_table is $wpdb->prefix + hardcoded suffix, not user input.
            $safe_table = esc_sql($table_name);
            if ($profile_id !== null) {
                $backup_info = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT `backupend` FROM `{$safe_table}` WHERE `status` = 'complete' AND `profile_id` = %s ORDER BY `backupend` DESC LIMIT 0,1",
                        $profile_id
                    )
                );
            } else {
                $backup_info = $wpdb->get_var(
                    "SELECT `backupend` FROM `{$safe_table}` WHERE `status` = 'complete' ORDER BY `backupend` DESC LIMIT 0,1"
                );
            }
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            wp_cache_set($cache_key, $backup_info);
        }

        if (!$backup_info) {
            return false;
        }

        return date_create($backup_info);
    }

    /**
     * @return array
     */
    public function get_backup_list()
    {
        // We use Akeeba API to get a list of backups
        return array();
    }
}
