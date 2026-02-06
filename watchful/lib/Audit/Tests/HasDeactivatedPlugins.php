<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasDeactivatedPlugins extends AbstractAudit
{
    public function __construct()
    {
        parent::__construct();
        if (!function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
    }

    public function run(?int $start = 0): stdClass
    {
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        if (count($all_plugins) === count($active_plugins)) {
            return $this->response->send_ok();
        }

        return $this->response->send_ko($this->get_inactive_plugins($all_plugins, $active_plugins));
    }

    /**
     * Get the list of inactive plugins
     *
     * @param array $plugins List of plugins.
     * @param array $active_plugins List of active plugins.
     *
     * @return array of objects
     */
    private function get_inactive_plugins(array $plugins, array $active_plugins): array
    {
        $inactive = [];

        foreach ($plugins as $key => $item) {
            if (in_array($key, $active_plugins, true)) {
                continue;
            }

            $t = new stdClass();
            $t->name = $item['Name'];
            $t->key = $key;

            $inactive[] = $t;
        }

        return $inactive;
    }
}
