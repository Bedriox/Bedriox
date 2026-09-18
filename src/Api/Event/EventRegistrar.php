<?php

declare(strict_types=1);

namespace Bedriox\Api\Event;

interface EventRegistrar
{
    /** @param class-string<Event> $eventClass */
    public function listen(
        string $eventClass,
        callable $listener,
        EventPriority $priority = EventPriority::NORMAL,
        bool $receiveCancelled = false,
    ): ListenerSubscription;

    public function registerSubscriber(object $subscriber): void;
}
