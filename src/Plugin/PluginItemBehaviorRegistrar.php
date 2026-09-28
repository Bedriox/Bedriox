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

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemBehaviorDefinition;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Server\Gameplay\Item\ArmorDefinition;
use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ConsumableDefinition;
use Bedriox\Server\Gameplay\Item\ItemBehaviorRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemType;
use Bedriox\Server\Gameplay\Item\ItemUseBehavior;
use InvalidArgumentException;
use Throwable;

/** @internal Adapts bounded public item-use definitions into plugin-owned simulation behavior. */
final class PluginItemBehaviorRegistrar
{
    private const string OWNERSHIP_RESOURCE = 'item-use-behaviors';

    /** @var array<string, true> */
    private array $owned = [];

    /** @var array<string, string> canonical identifier to lowercase plugin owner */
    private array $definitionOwners = [];

    /** @var array<string, ItemType> catalog definitions restored when an owner is released */
    private array $catalogBaselines = [];

    public function __construct(
        private readonly ItemBehaviorRegistry $behaviors,
        private readonly PluginOwnershipRegistry $ownership,
        private readonly ?ItemCatalog $items = null,
    ) {}

    public function register(
        string $plugin,
        string $identifier,
        ItemBehaviorDefinition $definition,
        bool $replace = false,
    ): void {
        $key = strtolower($plugin);
        $existingOwner = $this->definitionOwners[$identifier] ?? null;
        if ($existingOwner !== null && $existingOwner !== $key) {
            throw new InvalidArgumentException('Item behavior is owned by another plugin.');
        }
        if ($existingOwner !== null && !$replace) {
            throw new InvalidArgumentException('Item behavior is already registered by this plugin.');
        }

        $behavior = $this->adaptUse($plugin, $identifier, $definition);
        $catalogType = $this->adaptEquipment($identifier, $definition, $replace, $existingOwner);
        $newOwnership = !isset($this->owned[$key]);
        if ($newOwnership) {
            $this->ownership->own($plugin, self::OWNERSHIP_RESOURCE, function () use ($plugin, $key): void {
                $this->behaviors->unregisterOwnedBy($plugin);
                foreach ($this->definitionOwners as $identifier => $owner) {
                    if ($owner !== $key) {
                        continue;
                    }
                    if (isset($this->catalogBaselines[$identifier]) && $this->items !== null) {
                        $this->items->register($this->catalogBaselines[$identifier], true);
                        unset($this->catalogBaselines[$identifier]);
                    }
                    unset($this->definitionOwners[$identifier]);
                }
                unset($this->owned[$key]);
            });
            $this->owned[$key] = true;
        }

        $catalog = $this->items;
        $previousType = null;
        try {
            if ($catalogType !== null) {
                $catalog ??= throw new \LogicException('Item equipment composition is unavailable.');
                $previousType = $catalog->type($identifier);
                $this->catalogBaselines[$identifier] ??= $previousType;
                $catalog->register($catalogType, true);
            }
            if ($behavior !== null) {
                $this->behaviors->registerOwned($behavior, $replace);
            } elseif ($existingOwner !== null) {
                $this->behaviors->unregisterOwned($identifier, $plugin);
            }
            $this->definitionOwners[$identifier] = $key;
        } catch (Throwable $failure) {
            if ($previousType !== null) {
                $catalog->register($previousType, true);
            }
            if ($existingOwner === null) {
                unset($this->catalogBaselines[$identifier]);
            }
            if ($newOwnership) {
                $this->ownership->forget($plugin, self::OWNERSHIP_RESOURCE);
                unset($this->owned[$key]);
            }
            throw $failure;
        }
    }

    private function adaptUse(
        string $plugin,
        string $identifier,
        ItemBehaviorDefinition $definition,
    ): ?ItemUseBehavior {
        if ($definition->kind === null || $definition->kind === ItemUseKind::EQUIP) {
            return null;
        }
        if ($definition->kind === ItemUseKind::INSTANT) {
            return new ItemUseBehavior(
                $identifier,
                0,
                cooldownTicks: $definition->cooldownTicks,
                owner: $plugin,
                kind: ItemUseKind::INSTANT,
            );
        }
        $consumable = $definition->consumable
            ?? throw new InvalidArgumentException('Consumable behavior requires nutrition data.');
        return new ItemUseBehavior(
            $identifier,
            $definition->useDurationTicks,
            new ConsumableDefinition(
                $consumable->foodRestore,
                $consumable->saturationRestore,
                $consumable->requiresHunger,
                residue: $consumable->residue,
            ),
            $definition->cooldownTicks,
            $plugin,
            ItemUseKind::CONSUME,
        );
    }

    private function adaptEquipment(
        string $identifier,
        ItemBehaviorDefinition $definition,
        bool $replace,
        ?string $existingOwner,
    ): ?ItemType {
        if ($definition->armor === null && !$definition->allowedInOffhand) {
            return $existingOwner === null ? null : ($this->catalogBaselines[$identifier] ?? null);
        }
        if ($this->items === null) {
            throw new InvalidArgumentException('Armor and offhand behavior require an item catalog.');
        }
        $existing = $existingOwner === null
            ? $this->items->type($identifier)
            : ($this->catalogBaselines[$identifier] ?? $this->items->type($identifier));
        if (!$replace && $existingOwner === null
            && (($definition->armor !== null && $existing->armor !== null)
                || ($definition->allowedInOffhand && $existing->allowedInOffhand))) {
            throw new InvalidArgumentException('Item equipment behavior is already registered.');
        }
        $armor = $definition->armor === null
            ? $existing->armor
            : new ArmorDefinition(
                self::armorSlot($definition->armor->slot),
                $definition->armor->defensePoints,
                $definition->armor->maximumDurability,
                $definition->armor->knockbackResistance,
            );

        return new ItemType(
            $existing->identifier,
            $armor === null ? $existing->maximumStackSize : 1,
            $existing->tool,
            $existing->placedBlockState,
            $existing->networkBlockState,
            $existing->creative,
            $existing->owner,
            $armor,
            $existing->allowedInOffhand || $definition->allowedInOffhand,
        );
    }

    private static function armorSlot(EquipmentSlot $slot): ArmorSlot
    {
        return match ($slot) {
            EquipmentSlot::HEAD => ArmorSlot::Head,
            EquipmentSlot::CHEST => ArmorSlot::Chest,
            EquipmentSlot::LEGS => ArmorSlot::Legs,
            EquipmentSlot::FEET => ArmorSlot::Feet,
            default => throw new InvalidArgumentException('Armor behavior requires an armor equipment slot.'),
        };
    }
}
