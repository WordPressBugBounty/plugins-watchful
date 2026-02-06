<?php

namespace Watchful\Audit\Tests;

use stdClass;
use Watchful\Audit\AbstractAudit;
use Watchful\Audit\Files\RecursiveListing;

class HasInstallOnSubdirectory extends AbstractAudit
{
    public function run(?int $start = 0): stdClass
    {
        $recursive_listing = new RecursiveListing();
        $structure = $recursive_listing->get_structure(ABSPATH);

        $elements = $structure->files;
        $paths = [];

        $escaped_base_path = preg_replace(['#\/#', '#\.#'], ['\/', '\.'], ABSPATH);
        $pattern = '#^'.$escaped_base_path.'([a-z0-9_\-\.\s]*\/){1,2}wp-config\.php$#i';

        foreach ($elements as $element) {
            if (preg_match($pattern, $element) && $this->is_a_wp_config_file($element)) {
                $relative_path = str_replace(ABSPATH, '', $element);
                $paths[] = preg_replace('#wp-config.php$#', '', $relative_path);
            }
        }

        if (count($paths)) {
            return $this->response->send_ko($paths);
        }

        return $this->response->send_ok();
    }

    private function is_a_wp_config_file(string $file_path): bool
    {
        $content = implode('', file($file_path, FILE_IGNORE_NEW_LINES));

        return stristr($content, "define('ABSPATH'");
    }
}
