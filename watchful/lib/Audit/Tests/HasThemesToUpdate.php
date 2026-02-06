<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasThemesToUpdate extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $list = $this->get_update_list();

        if (!$list || !count($list)) {
            return $this->response->send_ok();
        }

        return $this->response->send_ko($list);
    }


    /**
     * Get list of themes to update.
     */
    private function get_update_list(): ?array
    {
        $status = get_site_transient('update_themes');

        if (false === $status) {
            wp_update_themes();
            set_transient('update_themes', false);
        }

        $status = get_site_transient('update_themes');
        $to_update = array();

        if (empty($status)) {
            return null;
        }

        foreach ($status->checked as $key => $version) {
            if (!isset($status->response[$key]['new_version']) || $version === $status->response[$key]['new_version']) {
                continue;
            }

            $t = new stdClass();
            $t->name = $key;
            $t->current = $version;
            $t->latest = $status->response[$key]['new_version'];

            $to_update[] = $t;
        }

        return $to_update;
    }
}
