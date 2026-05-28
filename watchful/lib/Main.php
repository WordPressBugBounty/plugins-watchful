<?php
/**
 * Main Watchful class.
 *
 * @version   2016-12-20 11:41 UTC+01
 * @package   Watchful WP Client
 * @author    Watchful
 * @authorUrl https://watchful.net
 * @copyright Copyright (c) 2020 watchful.net
 * @license   GNU/GPL
 */

namespace Watchful;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Main Watchful class.
 */
class Main
{

    /**
     * Plugin version, used for cache-busting of style and script file references.
     *
     * @since 0.1
     *
     * @var string
     */
    public $version = '0.1';

    /**
     * Plugin file.
     *
     * @since 0.1
     *
     * @var string
     */
    public $file = __FILE__;

    /**
     * Constructor
     */
    public function __construct()
    {
    } // end constructor

    /**
     * Initializes the plugin by setting localization, filters, and administration functions.
     */
    public function init()
    {
        // Add Message after activation.
        add_action('admin_notices', array($this, 'watchful_admin_notice'));
        add_action('network_admin_notices', array($this, 'watchful_admin_notice'));
    }

    /**
     * Display admin notice.
     *
     * @since 0.1
     */
    public function watchful_admin_notice()
    {
        // Make sure the plugin is activated.
        if (is_plugin_active('watchful/watchful.php')) {
            global $pagenow;

            // Only need to display this on the plugin view page.
            if ('plugins.php' !== $pagenow || !current_user_can('install_plugins') || !get_option('watchfulMessage')) {
                return;
            }
            ?>
            <div id="message" class="updated notice is-dismissible">
                <?php
                $my_settings_page = new Settings();
                $my_settings_page->print_watchful_form();
                ?>
                <br/>
            </div>
            <?php
        }
    }

} // end Watchful_Main Class
