<?php

namespace Watchful\Helpers;

use Theme_Upgrader;
use Watchful\Exception;
use Watchful\Helpers\Files as FilesHelper;
use Watchful\Skins\SkinThemeUpgrader;

class ThemeUpdater
{
    private $logger;

    public function __construct()
    {
        $this->logger = new Logger();
    }

    /**
     * @param string|null $slug
     * @param string|null $zip
     * @param bool $enable_maintenance_mode
     * @param string|null $new_version
     *
     * @return array
     * @throws Exception
     */
    public function update_theme(
        $slug = null,
        $zip = null,
        $enable_maintenance_mode = false,
        $handle_shutdown = false,
        $new_version = null
    ) {
        require_once ABSPATH.'wp-admin/includes/theme.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.WPINC.'/theme.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        $this->logger->log('Theme update started', [
            'theme_slug' => $slug,
            'zip' => $zip,
            'enable_maintenance_mode' => $enable_maintenance_mode,
            'handle_shutdown' => $handle_shutdown,
            'new_version' => $new_version,
        ]);

        if (empty($slug) && empty($zip)) {
            $this->logger->log('parameter is missing. slug required or zip', [
                'theme_slug' => $slug,
                'zip' => $zip,
            ],                 Logger::WARNING);
            throw new Exception('parameter is missing. slug required or zip', 400);
        }

        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            $this->logger->log('file modification is disabled (DISALLOW_FILE_MODS)', [], Logger::WARNING);
            throw new Exception('file modification is disabled (DISALLOW_FILE_MODS)', 403);
        }

        // If slug is missing we need to get it from the zip.
        if ($zip && !$slug) {
            $slug = $this->get_slug_from_zip($zip);
        }

        // Force a theme update check.
        wp_update_themes();
        $skin = new SkinThemeUpgrader();
        $upgrader = new Theme_Upgrader($skin);
        $theme_backup_manager = new ThemeBackupManager();

        $min_php_version = $this->next_version_info($slug);

        if ($zip) {
            $this->logger->log('Updating theme from zip', [
                'theme_slug' => $slug,
                'zip' => $zip,
                'new_version' => $new_version,
            ]);

            $this->update_from_zip($zip, $slug, $new_version);
        }

        if (version_compare(phpversion(), $min_php_version) < 0) {
            $this->logger->log('PHP version is not compatible with the update', [
                'theme_slug' => $slug,
                'php_version' => phpversion(),
                'min_php_version' => $min_php_version,
            ],                 Logger::WARNING);
            throw new Exception("The minimum required PHP version for this update is ".$min_php_version, 500);
        }

        if ($enable_maintenance_mode) {
            WP_Filesystem();
            $upgrader->maintenance_mode(true);
            $this->logger->log('Enabled maintenance mode');
        }

        if ($handle_shutdown) {
            $result = $theme_backup_manager->make_backup($slug);
            $this->logger->log('Backup created', [
                'theme_slug' => $slug,
                'result' => $result,
            ]);
        }

        remove_action('upgrader_process_complete', array('Language_Pack_Upgrader', 'async_upgrade'), 20);

        try {
            $this->logger->log('Starting theme update', [
                'theme_slug' => $slug,
            ]);
            $result = $upgrader->upgrade($slug);
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode(false);
            }
        } catch (Exception $e) {
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode(false);
            }
            $this->handle_update_error(
                $handle_shutdown,
                $slug,
                $theme_backup_manager,
                $e->getMessage()
            );
        }

        if (is_wp_error($result)) {
            $this->handle_update_error(
                $handle_shutdown,
                $slug,
                $theme_backup_manager,
                $result->get_error_message(),
                $result->get_error_code()
            );
        }
        if (is_wp_error($skin->error)) {
            $this->handle_update_error(
                $handle_shutdown,
                $slug,
                $theme_backup_manager,
                $skin->error->get_error_message(),
                $skin->error->get_error_code()
            );
        }

        // This default Exception should not be thrown because WP_Errors should be encountered just above.
        if (false === $result || is_null($result)) {
            $this->handle_update_error(
                $handle_shutdown,
                $slug,
                $theme_backup_manager,
                'unknown error'
            );
        }

        $this->logger->log('Theme update completed successfully', [
            'theme_slug' => $slug,
            'version' => wp_get_theme($slug)['Version'],
        ]);

        return [
            'status' => 'success',
            'version' => wp_get_theme($slug)['Version'],
        ];
    }

    /**
     * Get the slug of a theme from his zip file.
     *
     * @param string $zip The zip file.
     *
     * @return bool
     */
    private function get_slug_from_zip($zip)
    {
        $helper = new FilesHelper();

        $this->potential_slugs = $helper->get_zip_directories($zip);

        return $this->get_slug_from_list($this->potential_slugs);
    }

    /**
     * Get the correct slug from a given list.
     *
     * @param array $list List of slugs.
     *
     * @return string|bool
     */
    private function get_slug_from_list($list)
    {
        foreach ($list as $slug) {
            if ($this->is_installed($slug)) {
                return $slug;
            }
        }

        return false;
    }

    /**
     * Check if a theme is already installed.
     *
     * @param string $slug The theme slug.
     *
     * @return bool
     */
    public function is_installed($slug)
    {
        $themes = wp_get_themes();

        foreach ($themes as $path => $theme) {
            if ($path === $slug || in_array($slug, explode('/', $path), true)) {
                return true;
            }
        }

        return false;
    }

    private function next_version_info($slug)
    {
        $current = get_site_transient('update_themes');

        if (isset($current->response[$slug])) {
            return $current->response[$slug]['requires_php'];
        }

        return phpversion();
    }

    /**
     * Override the zip file in the themes list used by the upgrader.
     *
     * @param string $zip The zip file.
     * @param string $slug The theme path.
     */
    private function update_from_zip($zip, $slug, $new_version = null)
    {
        $current = get_site_transient('update_themes');

        if (!isset($current->response[$slug]) && !$new_version) {
            return;
        }

        $theme = wp_get_theme($slug);
        if (empty($theme)) {
            return;
        }

        add_filter('pre_set_site_transient_update_themes', function ($value) use ($theme, $zip, $slug, $new_version) {
            $template = $theme->get_template();
            if (!isset($value->response[$template])) {
                $value->response[$template] = [
                    'theme' => $template,
                    'new_version' => $new_version,
                    'url' => $theme->get('ThemeURI'),
                ];
            }

            $value->response[$slug]['package'] = $zip;

            return $value;
        });

        set_site_transient('update_themes', $current);
    }

    /**
     * @param bool $handle_shutdown
     * @param string $slug
     * @param ThemeBackupManager $theme_backup_manager
     * @return void
     * @throws Exception
     */
    private function handle_update_error(
        $handle_shutdown,
        $slug,
        $theme_backup_manager,
        $error_message = null,
        $error_code = 500
    ) {
        $is_installed = $this->is_installed($slug);

        if ($handle_shutdown && !$is_installed) {
            add_action('shutdown', [$theme_backup_manager, 'restore_backup'], 0, false);
        }

        $this->logger->log('Theme update error', [
            'theme_slug' => $slug,
            'error_message' => $error_message,
            'error_code' => $error_code,
            'is_installed' => $is_installed,
            'handle_shutdown' => $handle_shutdown,
        ]);

        throw new Exception($error_message, $error_code, [
            'theme' => $slug,
            'is_installed' => $this->is_installed($slug),
            'handle_shutdown' => $handle_shutdown,
        ]);
    }
}