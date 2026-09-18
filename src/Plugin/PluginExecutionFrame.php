<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Event\EventPriority;

final readonly class PluginExecutionFrame
{
    public function __construct(
        public string $plugin,
        public string $version,
        public string $operation,
        public ?string $event = null,
        public ?string $listener = null,
        public ?EventPriority $priority = null,
        public int $startedAtNanoseconds = 0,
    ) {}
}
