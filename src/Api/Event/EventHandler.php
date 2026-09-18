<?php

declare(strict_types=1);

namespace Bedriox\Api\Event;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class EventHandler
{
    public function __construct(
        public EventPriority $priority = EventPriority::NORMAL,
        public bool $receiveCancelled = false,
    ) {}
}
