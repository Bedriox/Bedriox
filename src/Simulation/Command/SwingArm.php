<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Simulation\ArmSwingSource;

final readonly class SwingArm implements WorldCommand
{
    public function __construct(
        public string $session,
        public ArmSwingSource $source,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 24 + strlen($this->session);
    }
}
