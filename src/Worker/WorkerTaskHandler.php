<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

interface WorkerTaskHandler
{
    public function execute(string $payload): string;
}
