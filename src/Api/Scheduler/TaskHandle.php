<?php

declare(strict_types=1);

namespace Bedriox\Api\Scheduler;

interface TaskHandle
{
    public function id(): int;

    public function state(): TaskState;

    public function cancel(): void;
}
