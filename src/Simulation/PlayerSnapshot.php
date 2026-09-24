<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Player\InventoryStack;

final readonly class PlayerSnapshot
{
    /**
     * @param list<InventoryStack|null> $armor
     */
    public function __construct(
        public string $sessionId,
        public string $identity,
        public string $displayName,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public MovementMode $movementMode,
        public int $movementSequence,
        public VerticalState $verticalState,
        public float $verticalVelocity,
        public int $runtimeActorId = 0,
        public float $headYaw = 0.0,
        public bool $sneaking = false,
        public bool $sprinting = false,
        public float $health = 20.0,
        public bool $alive = true,
        public GameMode $gameMode = GameMode::SURVIVAL,
        public float $food = 20.0,
        public float $saturation = 20.0,
        public float $exhaustion = 0.0,
        public array $armor = [],
        public ?InventoryStack $offhand = null,
        public int $selectedHotbarSlot = 0,
        public ?InventoryStack $selectedStack = null,
    ) {}
}
