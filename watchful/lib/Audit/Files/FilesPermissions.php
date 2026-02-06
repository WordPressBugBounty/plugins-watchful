<?php

namespace Watchful\Audit\Files;

use Exception;
use stdClass;
use Watchful\Audit\AbstractAudit;
use Watchful\Helpers\FSPermissions;

class FilesPermissions extends AbstractAudit
{
    const MINIMUMFILESPERMISSION = 644;

    /**
     * Audit the file system permissions.
     */
    public function run(?int $start = null): stdClass
    {
        $recursive_listing = new RecursiveListing();
        $structure = $recursive_listing->get_structure(ABSPATH);

        $files = $structure->files;
        $result = new stdClass();
        $result->wrong = []; // Files with wrong permission.
        $result->unchecked = []; // Files no checked.
        $result->size = count($files);
        $result->start = $start;

        if ($this->is_windows()) {
            $result->end = $result->size;

            return $result;
        }

        $current = $start;
        while ($this->have_time() && $current < $result->size - 1) {
            $path_from_root = str_replace(ABSPATH, '/', $files[$current]);
            try {
                $permission = FSPermissions::from_path($files[$current]);
                if ($permission->is_higher(self::MINIMUMFILESPERMISSION)) {
                    $result->wrong[] = [$path_from_root, $permission->get_unix()];
                }
            } catch (Exception $e) {
                $result->unchecked[] = [$path_from_root, null];
            }
            $current++;
        }

        $result->lastFileChecked = $files[$current]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        $result->end = $current;

        return $result;
    }
}