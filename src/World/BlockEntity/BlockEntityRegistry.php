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

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Closed registry for canonical identities and their Mojang LevelDB save identifiers. */
final readonly class BlockEntityRegistry
{
    /** @var array<string, BlockEntityType> */
    private array $persistentIds;

    /** @var array<string, string> */
    private array $saveIds;

    public function __construct()
    {
        $this->persistentIds = [
            'Chest' => BlockEntityType::Chest,
            'minecraft:chest' => BlockEntityType::Chest,
            'Barrel' => BlockEntityType::Barrel,
            'minecraft:barrel' => BlockEntityType::Barrel,
            'ShulkerBox' => BlockEntityType::ShulkerBox,
            'minecraft:shulker_box' => BlockEntityType::ShulkerBox,
            'EnderChest' => BlockEntityType::EnderChest,
            'minecraft:ender_chest' => BlockEntityType::EnderChest,
            'BrewingStand' => BlockEntityType::BrewingStand,
            'minecraft:brewing_stand' => BlockEntityType::BrewingStand,
        ];
        $this->saveIds = [
            BlockEntityType::Chest->value => 'Chest',
            BlockEntityType::Barrel->value => 'Barrel',
            BlockEntityType::ShulkerBox->value => 'ShulkerBox',
            BlockEntityType::EnderChest->value => 'EnderChest',
            BlockEntityType::BrewingStand->value => 'BrewingStand',
        ];
    }

    public function fromPersistentId(string $id): BlockEntityType
    {
        return $this->persistentIds[$id]
            ?? throw new InvalidArgumentException("Block-entity save identifier '$id' is not registered.");
    }

    public function persistentId(BlockEntityType $type): string
    {
        return $this->saveIds[$type->value];
    }

    public function create(BlockEntityType $type, BlockPosition $position): BlockEntity
    {
        return match ($type) {
            BlockEntityType::BrewingStand => BrewingStandBlockEntity::empty($position),
            BlockEntityType::Chest, BlockEntityType::Barrel, BlockEntityType::ShulkerBox =>
                ContainerBlockEntity::empty($type, $position),
            BlockEntityType::EnderChest => new SimpleBlockEntity($type, $position),
        };
    }
}
