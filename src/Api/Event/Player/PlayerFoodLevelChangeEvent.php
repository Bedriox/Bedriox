<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\FoodLevelChangeCause;
use Bedriox\Api\Player\Nutrition;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable nutrition transition emitted before authoritative state changes. */
final class PlayerFoodLevelChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Nutrition $previous,
        private Nutrition $nutrition,
        public readonly FoodLevelChangeCause $cause,
    ) {}

    public function nutrition(): Nutrition
    {
        return $this->nutrition;
    }

    public function setNutrition(Nutrition $nutrition): void
    {
        $this->assertMutable();
        $this->nutrition = $nutrition;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->nutrition];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof Nutrition) {
            throw new InvalidArgumentException('Invalid player food-level event state.');
        }
        parent::replaceState($state[0]);
        $this->nutrition = $state[1];
    }
}
