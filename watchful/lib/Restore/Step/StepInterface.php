<?php

namespace Watchful\Restore\Step;

use Watchful\Restore\StepResponse;

interface StepInterface
{
    public function run(string $backup_id, array $data): StepResponse;
}