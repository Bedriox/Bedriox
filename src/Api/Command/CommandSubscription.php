<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

interface CommandSubscription
{
    public function unregister(): void;

    public function isRegistered(): bool;
}
