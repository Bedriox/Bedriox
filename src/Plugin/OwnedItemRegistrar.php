<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Command\CommandSoftEnum;
use Bedriox\Api\Inventory\ItemDefinition;
use Bedriox\Api\Inventory\ItemRegistrar;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemType;

final readonly class OwnedItemRegistrar implements ItemRegistrar
{
    public function __construct(
        private string $plugin,
        private ItemCatalog $items,
        private ?CommandSoftEnum $itemIdentifiers = null,
    ) {}

    public function register(ItemDefinition $definition, bool $replace = false): void
    {
        $existing = $this->items->has($definition->identifier)
            ? $this->items->type($definition->identifier)
            : null;
        $this->items->register(new ItemType(
            $definition->identifier,
            $definition->maximumStackSize,
            $existing?->tool,
            $existing?->placedBlockState,
            $existing?->networkBlockState,
            creative: $definition->creative,
            owner: $this->plugin,
        ), $replace);
        if ($this->itemIdentifiers !== null) {
            $this->itemIdentifiers->replace($this->items->commandIdentifiers());
        }
    }
}
