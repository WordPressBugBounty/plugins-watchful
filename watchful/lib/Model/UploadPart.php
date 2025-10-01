<?php

namespace Watchful\Model;

use JsonSerializable;

class UploadPart implements JsonSerializable
{
    /** @var string */
    public $part_number;

    /** @var string */
    public $e_tag;

    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->part_number = $data['part_number'] ?? '';
        $instance->e_tag = $data['e_tag'] ?? '';

        return $instance;
    }

    public function jsonSerialize(): array
    {
        return [
            'part_number' => $this->part_number,
            'e_tag' => $this->e_tag,
        ];
    }
}