<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Event;

use Bedriox\Api\Event\EventPriority;

final readonly class RegisteredListener
{
    /** @param callable $callback */
    public function __construct(
        public int $id,
        public int $sequence,
        public string $plugin,
        public string $eventClass,
        public mixed $callback,
        public EventPriority $priority,
        public bool $receiveCancelled,
        public string $description,
    ) {}
}
