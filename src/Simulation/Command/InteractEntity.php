<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Api\Entity\EntityInteractionType;

final readonly class InteractEntity implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $targetRuntimeActorId,
        public int $hotbarSlot,
        public EntityInteractionType $interaction,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 56 + strlen($this->session);
    }
}
