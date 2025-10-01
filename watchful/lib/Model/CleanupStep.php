<?php

namespace Watchful\Model;

use JsonSerializable;

/**
 * @property bool $completed
 */
class CleanupStep implements JsonSerializable
{
    /** @var bool */
    public $completed = false;

    public function jsonSerialize(): array
    {
        return [
            'completed' => $this->completed,
        ];
    }
}

