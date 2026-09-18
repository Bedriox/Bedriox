<?php

declare(strict_types=1);

namespace Bedriox\Api\Event;

interface ListenerSubscription
{
    public function unregister(): void;

    public function isRegistered(): bool;
}
