<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\World\BlockPosition;

final readonly class BreakBlock implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $sequence,
        public BlockBreakAction $action,
        public ?BlockPosition $position,
        public int $face,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 48 + strlen($this->session);
    }
}
