<?php

namespace Watchful\Model;

use JsonSerializable;

/**
 * @property bool $completed
 * @property int $current_offset
 * @property int $size_processed
 */
class ArchiveStep implements JsonSerializable
{
    /** @var bool */
    public $completed = false;
    /** @var int */
    public $current_offset = 0;
    /** @var int */
    public $size_processed = 0;

    public function jsonSerialize(): array
    {
        return [
            'completed' => $this->completed,
            'current_offset' => $this->current_offset,
            'size_processed' => $this->size_processed,
        ];
    }
}

