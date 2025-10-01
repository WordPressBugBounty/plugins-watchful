<?php

namespace Watchful\Model;

use JsonSerializable;

class FileEnumerationStep implements JsonSerializable
{
    /** @var bool */
    public $completed = false;
    /** @var int */
    public $current_offset = 0;
    /** @var int */
    public $size = 0;
    /** @var int */
    public $total_files = 0;
    /** @var int */
    public $changed_files = 0;
    /** @var int */
    public $deleted_files = 0;


    public function jsonSerialize(): array
    {
        return [
            'completed' => $this->completed,
            'current_offset' => $this->current_offset,
            'size' => $this->size,
            'total_files' => $this->total_files,
            'changed_files' => $this->changed_files,
            'deleted_files' => $this->deleted_files,
        ];
    }
}

