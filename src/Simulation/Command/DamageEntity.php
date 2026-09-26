<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Api\Entity\EntityDamageCause;

/** Bounded authoritative damage request for a non-player living entity. */
final readonly class DamageEntity implements WorldCommand
{
    public function __construct(
        public string $source,
        public int $runtimeId,
        public string $uniqueId,
        public float $amount,
        public EntityDamageCause $cause,
    ) {}

    public function sessionId(): string
    {
        return $this->source;
    }

    public function estimatedBytes(): int
    {
        return 80 + strlen($this->source) + strlen($this->uniqueId);
    }
}
