<?php

namespace Watchful\Model;

use JsonSerializable;

/**
 * @property bool $completed
 * @property mixed $resume_data
 */
class UploadStep implements JsonSerializable
{
    /** @var bool */
    public $completed = false;

    /** @var int */
    public $current_offset = 0;

    /** @var int */
    public $part_number = 0;

    /** @var int */
    public $total_size = 0;

    /** @var int */
    public $total_parts = 0;

    /** @var UploadPart[] */
    public $parts = [];

    public function jsonSerialize(): array
    {
        return [
            'completed' => $this->completed,
            'current_offset' => $this->current_offset,
            'part_number' => $this->part_number,
            'total_size' => $this->total_size,
            'total_parts' => $this->total_parts,
            'parts' => $this->parts,
        ];
    }
}

