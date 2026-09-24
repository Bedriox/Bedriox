<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ConsumptionResult;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Nutrition;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable result emitted immediately before consumption commits. */
final class PlayerItemConsumeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
        public readonly Nutrition $nutrition,
        private ConsumptionResult $result,
    ) {}

    public function result(): ConsumptionResult
    {
        return $this->result;
    }

    public function setResult(ConsumptionResult $result): void
    {
        $this->assertMutable();
        $this->result = $result;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->result];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof ConsumptionResult) {
            throw new InvalidArgumentException('Invalid player item consume event state.');
        }
        parent::replaceState($state[0]);
        $this->result = $state[1];
    }
}
