<?php

namespace Watchful\Model;

use JsonSerializable;

class BackupManifest implements JsonSerializable
{
    /** @var string */
    public $id;

    /** @var string */
    public $backup_type;

    /** @var string|null */
    public $full_backup_id = null;

    /** @var int */
    public $created_at;

    /** @var int */
    public $completed_at = 0;

    /** @var string */
    public $plugin_version = '';

    /** @var string */
    public $cms_version = '';

    /** @var string */
    public $cms_abs_path = '';

    /** @var string */
    public $php_version = '';

    /** @var string */
    public $site_url = '';

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'backup_type' => $this->backup_type,
            'full_backup_id' => $this->full_backup_id,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
            'plugin_version' => $this->plugin_version,
            'cms_version' => $this->cms_version,
            'cms_abs_path' => $this->cms_abs_path,
            'php_version' => $this->php_version,
            'site_url' => $this->site_url,
        ];
    }
}