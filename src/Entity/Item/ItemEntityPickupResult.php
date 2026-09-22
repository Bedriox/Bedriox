<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Item;

use Bedriox\Server\Player\InventoryStack;

/** Authoritative result after inventory capacity has selected an accepted item count. */
final readonly class ItemEntityPickupResult
{
    public function __construct(
        public int $uniqueEntityId,
        public int $runtimeEntityId,
        public InventoryStack $pickedUp,
        public ?InventoryStack $remaining,
    ) {}

    public function removed(): bool
    {
        return $this->remaining === null;
    }
}
