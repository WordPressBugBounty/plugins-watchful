<?php

namespace Watchful\Audit\Files;

use Exception;
use stdClass;
use Watchful\Audit\AbstractAudit;
use Watchful\Helpers\FSPermissions;

class FoldersPermissions extends AbstractAudit
{
    const MINIMUMFOLDERSPERMISSION = 755;

    /**
     * Audit the file system permissions.
     */
    public function run(?int $start = null): stdClass
    {
        $recursive_listing = new RecursiveListing();
        $structure = $recursive_listing->get_structure(ABSPATH);

        $folders = $structure->dirs;

        $result = new stdClass();
        $result->wrong = []; // Files with wrong permission.
        $result->unchecked = []; // Files with wrong permission.
        $result->size = count($folders);
        $result->start = $start;

        if ($this->is_windows()) {
            $result->end = $result->size;

            return $result;
        }

        $current = $start;
        $folders_count = count($folders);
        while ($this->have_time() && $current < $folders_count - 1) {
            $path_from_root = preg_replace('#^'.substr(ABSPATH, 0, -1).'#i', '', $folders[$current]);
            $path_from_root = ('' === $path_from_root) ? '/' : $path_from_root;

            try {
                $permission = FSPermissions::from_path($folders[$current]);
                if ($permission->is_higher(self::MINIMUMFOLDERSPERMISSION)) {
                    $result->wrong[] = [$path_from_root, $permission->get_unix()];
                }
            } catch (Exception $e) {
                $result->unchecked[] = [$path_from_root, null];
            }
            $current++;
        }
        $result->lastFolderChecked = $folders[$current]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        $result->end = $current;

        return $result;
    }
}