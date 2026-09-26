<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Server\Gameplay\Item\ItemCatalog;

final readonly class GameplayLootItemRegistry implements LootItemRegistry
{
    public function __construct(private ItemCatalog $items) {}

    public function maximumStackSize(string $identifier): ?int
    {
        return $this->items->has($identifier) ? $this->items->type($identifier)->maximumStackSize : null;
    }
}
