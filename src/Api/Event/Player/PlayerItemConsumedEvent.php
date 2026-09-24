<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\ConsumptionResult;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Nutrition;
use Bedriox\Api\Player\Player;

/** Immutable notification emitted after consumption and residue routing commit. */
final class PlayerItemConsumedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
        public readonly Nutrition $previousNutrition,
        public readonly Nutrition $nutrition,
        public readonly ConsumptionResult $result,
    ) {}
}
