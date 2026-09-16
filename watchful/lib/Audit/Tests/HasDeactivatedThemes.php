<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;

class HasDeactivatedThemes extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $all_themes = wp_get_themes();
        $inactive_themes = $this->get_inactive_theme($all_themes);

        if (!empty($inactive_themes)) {
            return $this->response->send_ko($inactive_themes);
        }

        return $this->response->send_ok();
    }

    private function get_inactive_theme(array $theme_list): array
    {
        $active_theme = wp_get_theme();
        $active_stylesheet = $active_theme->get_stylesheet();
        $active_template = $active_theme->get_template();
        $inactive = array();

        foreach ($theme_list as $stylesheet => $item) {
            // An active child theme requires its parent to remain installed, even though
            // the parent itself is inactive.
            if ($stylesheet === $active_stylesheet || $stylesheet === $active_template) {
                continue;
            }

            $t = new stdClass();
            $t->name = $item->Name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

            $inactive[] = $t;
        }

        return $inactive;
    }
}
