<?php

namespace Watchful\Model;

use JsonSerializable;

/**
 * @property bool $completed
 * @property int $current_table
 * @property int $current_offset
 * @property int $total_rows
 * @property int $rows_processed
 */
class DatabaseStep implements JsonSerializable
{
    /** @var bool */
    public $completed = false;
    /** @var int */
    public $current_table = 0;
    /** @var int */
    public $current_offset = 0;
    /** @var int */
    public $total_rows = 0;
    /** @var int */
    public $rows_processed = 0;

    public function jsonSerialize(): array
    {
        return [
            'completed' => $this->completed,
            'current_table' => $this->current_table,
            'current_offset' => $this->current_offset,
            'total_rows' => $this->total_rows,
            'rows_processed' => $this->rows_processed,
        ];
    }
}

