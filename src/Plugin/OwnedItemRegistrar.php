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
            $identifiers = [];
            foreach ($this->items->all() as $type) {
                $identifiers[] = $type->identifier;
                if (str_starts_with($type->identifier, 'minecraft:')) {
                    $identifiers[] = substr($type->identifier, strlen('minecraft:'));
                }
            }
            natcasesort($identifiers);
            $this->itemIdentifiers->replace(array_values($identifiers));
        }
    }
}
