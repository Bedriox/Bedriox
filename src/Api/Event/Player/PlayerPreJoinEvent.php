<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;

final class PlayerPreJoinEvent extends CancellableEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $uuid,
    ) {}
}
