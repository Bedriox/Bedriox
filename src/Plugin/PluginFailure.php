<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Throwable;

final readonly class PluginFailure
{
    public function __construct(
        public string $plugin,
        public string $operation,
        public Throwable $throwable,
        public ?PluginExecutionFrame $frame,
    ) {}
}
