<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

interface CommandJobSubscription
{
    public function cancel(): void;

    public function isRunning(): bool;
}
