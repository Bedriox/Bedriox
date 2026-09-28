<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Command\CommandSoftEnum;
use Bedriox\Api\Inventory\ItemBehaviorDefinition;
use Bedriox\Api\Inventory\ItemDefinition;
use Bedriox\Api\Inventory\ItemRegistrar;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemType;
use Closure;
use LogicException;

final readonly class OwnedItemRegistrar implements ItemRegistrar
{
    public function __construct(
        private string $plugin,
        private ItemCatalog $items,
        private ?CommandSoftEnum $itemIdentifiers = null,
        /** @var null|Closure(string, string, ItemBehaviorDefinition, bool): void */
        private ?Closure $behaviorRegistration = null,
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
            armor: $existing?->armor,
            allowedInOffhand: $existing === null ? false : $existing->allowedInOffhand,
        ), $replace);
        if ($this->itemIdentifiers !== null) {
            $this->itemIdentifiers->replace($this->items->commandIdentifiers());
        }
    }

    public function registerBehavior(
        string $identifier,
        ItemBehaviorDefinition $definition,
        bool $replace = false,
    ): void {
        $this->items->type($identifier);
        if ($definition->consumable !== null) {
            foreach ($definition->consumable->residue as $residue) {
                $this->items->type($residue->identifier);
            }
        }
        if ($this->behaviorRegistration === null) {
            throw new LogicException('Item behavior registration is unavailable in this plugin context.');
        }

        ($this->behaviorRegistration)($this->plugin, $identifier, $definition, $replace);
    }
}
