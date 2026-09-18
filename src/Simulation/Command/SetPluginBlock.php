<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\World\BlockPosition;

final readonly class SetPluginBlock implements WorldCommand
{
    public function __construct(
        public string $plugin,
        public BlockPosition $position,
        public string $identifier,
    ) {}

    public function sessionId(): string
    {
        return 'plugin:' . $this->plugin;
    }

    public function estimatedBytes(): int
    {
        return 64 + strlen($this->plugin) + strlen($this->identifier);
    }
}
