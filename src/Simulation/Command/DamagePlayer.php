<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Simulation\DamageCause;

final readonly class DamagePlayer implements WorldCommand
{
    public function __construct(public string $session, public float $amount, public DamageCause $cause) {}
    public function sessionId(): string
    {
        return $this->session;
    }
    public function estimatedBytes(): int
    {
        return 48 + strlen($this->session);
    }
}
