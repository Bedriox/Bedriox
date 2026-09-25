<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use InvalidArgumentException;

/** Server-resolved recipe intent carried alongside one atomic inventory request. */
final readonly class CraftingRequest
{
    public function __construct(
        public int $recipeNetworkId,
        public int $repetitions,
        public bool $automatic = false,
    ) {
        if ($recipeNetworkId < 1 || $repetitions < 1 || $repetitions > 64) {
            throw new InvalidArgumentException('Crafting request values are outside their supported bounds.');
        }
    }
}
