<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\NutritionChangeReason;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class NutritionChanged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public PlayerSnapshot $player,
        public float $previousFood,
        public float $previousSaturation,
        public float $previousExhaustion,
        public NutritionChangeReason $reason,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
