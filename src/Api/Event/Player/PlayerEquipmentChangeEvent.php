<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable equipment transition emitted before authoritative commit. */
final class PlayerEquipmentChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly EquipmentSlot $slot,
        public readonly ?ItemStack $previous,
        private ?ItemStack $item,
    ) {}

    public function item(): ?ItemStack
    {
        return $this->item;
    }

    public function setItem(?ItemStack $item): void
    {
        $this->assertMutable();
        $this->item = $item;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->item];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || ($state[1] !== null && !$state[1] instanceof ItemStack)) {
            throw new InvalidArgumentException('Invalid player equipment event state.');
        }
        parent::replaceState($state[0]);
        $this->item = $state[1];
    }
}
