<?php
/**
 * Plugin Name: Watchful
 * Plugin URI: https://app.watchful.net
 * Description: Remote Website Management Plugin by Watchful
 * Version: 2.0.13
 * Author: watchful
 * Author URI: https://watchful.net
 * License: GPLv2 or later
 *
 * @package watchful
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once 'autoloader.php';
spl_autoload_register('watchful_class_loader');

if (!defined('WATCHFUL_PLUGIN_DIR')) {
    define('WATCHFUL_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('WATCHFUL_PLUGIN_CONTENT_DIR')) {
    define('WATCHFUL_PLUGIN_CONTENT_DIR', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'watchful');
}

if (!defined('WATCHFUL_VERSION')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    define('WATCHFUL_VERSION', get_plugin_data(__FILE__, false, false)['Version']);
}

register_activation_hook(__FILE__, array('watchful\Init', 'activation'));
register_uninstall_hook(__FILE__, array('watchful\Init', 'uninstall'));

// As soon as possible in the execution, we store the WP core version.
Watchful\Controller\Core::remember_wp_version();

add_action('init', array('watchful\Init', 'wordpress_init'));

add_action('admin_init', array('watchful\Init', 'admin_init'));

add_action('plugins_loaded', array('watchful\Init', 'plugins_loaded'));
