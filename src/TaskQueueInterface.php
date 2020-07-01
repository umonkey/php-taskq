<?php

declare(strict_types=1);

namespace Umonkey\TaskQueue;

interface TaskQueueInterface
{
    public function add(string $action, array $data = [], int $priority = 0): int;

    public function run(): void;
}
