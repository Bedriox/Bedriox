<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Event;

use Bedriox\Api\Event\ListenerSubscription;

final class EventSubscription implements ListenerSubscription
{
    private bool $registered = true;

    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly int $id,
    ) {}

    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }
        $this->registered = false;
        $this->dispatcher->unregister($this->id);
    }

    public function isRegistered(): bool
    {
        return $this->registered && $this->dispatcher->has($this->id);
    }
}
