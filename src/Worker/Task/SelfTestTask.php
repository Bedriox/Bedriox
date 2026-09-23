<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Task;

use Bedriox\Server\Worker\WorkerTaskHandler;

final class SelfTestTask implements WorkerTaskHandler
{
    public function execute(string $payload): string
    {
        return hash('sha256', $payload, true);
    }
}
