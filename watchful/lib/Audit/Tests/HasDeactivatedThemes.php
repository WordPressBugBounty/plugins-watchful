<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasDeactivatedThemes extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $all_themes = wp_get_themes();

        if (2 === (count($all_themes) && !is_child_theme()) || count($all_themes) > 1) {
            return $this->response->send_ko($this->get_inactive_theme($all_themes));
        }

        return $this->response->send_ok();
    }

    private function get_inactive_theme(array $theme_list): array
    {
        $active_theme = wp_get_theme();
        $inactive = array();

        foreach ($theme_list as $key => $item) {
            if ($item->Name === $active_theme->Name) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
                continue;
            }

            $t = new stdClass();
            $t->name = $item->Name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

            $inactive[] = $t;
        }

        return $inactive;
    }
}
