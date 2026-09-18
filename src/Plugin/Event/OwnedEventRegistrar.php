<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Event;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\EventPriority;
use Bedriox\Api\Event\EventRegistrar;
use Bedriox\Api\Event\ListenerSubscription;

final readonly class OwnedEventRegistrar implements EventRegistrar
{
    public function __construct(
        private string $plugin,
        private EventDispatcher $dispatcher,
    ) {}

    /** @param class-string<Event> $eventClass */
    public function listen(
        string $eventClass,
        callable $listener,
        EventPriority $priority = EventPriority::NORMAL,
        bool $receiveCancelled = false,
    ): ListenerSubscription {
        return $this->dispatcher->register(
            $this->plugin,
            $eventClass,
            $listener,
            $priority,
            $receiveCancelled,
        );
    }

    public function registerSubscriber(object $subscriber): void
    {
        $this->dispatcher->registerSubscriber($this->plugin, $subscriber);
    }
}
