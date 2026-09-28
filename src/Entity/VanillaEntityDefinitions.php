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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityIdentifier;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\EntityTypeRegistry;
use Bedriox\Server\Entity\Vanilla\CatalogMobEntity;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;

final class VanillaEntityDefinitions
{
    private static ?EntityDefinition $cow = null;
    private static ?EntityDefinition $zombie = null;

    public static function cow(): EntityDefinition
    {
        return self::$cow ??= new EntityDefinition(
            VanillaEntityType::COW,
            EntityCategory::ANIMAL,
            VanillaEntityType::COW->value,
            0.9,
            1.4,
            10.0,
        );
    }

    public static function zombie(): EntityDefinition
    {
        return self::$zombie ??= new EntityDefinition(
            VanillaEntityType::ZOMBIE,
            EntityCategory::MONSTER,
            VanillaEntityType::ZOMBIE->value,
            0.6,
            1.95,
            20.0,
            burnsInDaylight: true,
        );
    }

    /** @return list<RegisteredEntityDefinition> */
    public static function registrations(): array
    {
        return [
            new RegisteredEntityDefinition(
                self::cow(),
                static fn(string $uuid, int $runtimeId, string $world, \Bedriox\Server\Simulation\Position $position, float $yaw, float $pitch): CowEntity =>
                    new CowEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
            new RegisteredEntityDefinition(
                self::zombie(),
                static fn(string $uuid, int $runtimeId, string $world, \Bedriox\Server\Simulation\Position $position, float $yaw, float $pitch): ZombieEntity =>
                    new ZombieEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
        ];
    }

    /** @return list<RegisteredEntityDefinition> */
    public static function catalogRegistrations(EntityTypeRegistry $catalog): array
    {
        $registrations = [];
        foreach ($catalog->definitions() as $network) {
            if (!$network->hasSpawnEgg()) {
                continue;
            }
            $identifier = $network->identifier();
            if ($identifier === VanillaEntityType::COW->value) {
                $registrations[] = self::registrations()[0];
                continue;
            }
            if ($identifier === VanillaEntityType::ZOMBIE->value) {
                $registrations[] = self::registrations()[1];
                continue;
            }
            $category = self::category($identifier);
            if ($category === EntityCategory::MISCELLANEOUS) {
                continue;
            }
            [$width, $height] = self::dimensions($identifier, $category);
            $definition = new EntityDefinition(
                new VanillaEntityIdentifier($identifier),
                $category,
                $identifier,
                $width,
                $height,
                $category === EntityCategory::ANIMAL ? 10.0 : 20.0,
                gravity: in_array($category, [EntityCategory::WATER, EntityCategory::FLYING], true) ? 0.0 : 0.08,
            );
            $registrations[] = new RegisteredEntityDefinition(
                $definition,
                static fn(string $uuid, int $runtimeId, string $world, \Bedriox\Server\Simulation\Position $position, float $yaw, float $pitch): CatalogMobEntity =>
                    new CatalogMobEntity($uuid, $runtimeId, $definition, $world, $position, yaw: $yaw, pitch: $pitch),
            );
        }

        return $registrations;
    }

    private static function category(string $identifier): EntityCategory
    {
        $name = substr($identifier, strlen('minecraft:'));
        if (in_array($name, [
            'cod', 'dolphin', 'elder_guardian', 'glow_squid', 'guardian', 'pufferfish', 'salmon',
            'squid', 'tadpole', 'tropicalfish',
        ], true)) {
            return EntityCategory::WATER;
        }
        if (in_array($name, ['allay', 'bat', 'bee', 'happy_ghast', 'parrot'], true)) {
            return EntityCategory::FLYING;
        }
        if (str_contains($name, 'villager') || $name === 'wandering_trader') {
            return EntityCategory::VILLAGER;
        }
        if (in_array($name, [
            'blaze', 'bogged', 'breeze', 'creaking', 'creeper', 'drowned', 'elder_guardian', 'ender_dragon',
            'enderman', 'endermite', 'evocation_illager', 'ghast', 'guardian', 'hoglin', 'husk',
            'magma_cube', 'phantom', 'piglin', 'piglin_brute', 'pillager', 'ravager', 'shulker',
            'silverfish', 'skeleton', 'slime', 'spider', 'stray', 'vex', 'vindicator', 'warden',
            'witch', 'wither', 'wither_skeleton', 'zoglin', 'zombie', 'zombie_pigman',
            'zombie_villager_v2',
        ], true)) {
            return EntityCategory::MONSTER;
        }
        if (in_array($name, ['agent', 'armor_stand', 'npc'], true)) {
            return EntityCategory::MISCELLANEOUS;
        }

        return EntityCategory::ANIMAL;
    }

    /** @return array{float, float} */
    private static function dimensions(string $identifier, EntityCategory $category): array
    {
        return match ($identifier) {
            'minecraft:chicken' => [0.4, 0.7],
            'minecraft:rabbit' => [0.4, 0.5],
            'minecraft:bee', 'minecraft:bat' => [0.5, 0.6],
            'minecraft:spider' => [1.4, 0.9],
            'minecraft:slime', 'minecraft:magma_cube' => [1.0, 1.0],
            'minecraft:ender_dragon' => [16.0, 8.0],
            'minecraft:ghast' => [4.0, 4.0],
            default => $category === EntityCategory::ANIMAL ? [0.9, 1.4] : [0.6, 1.8],
        };
    }
}
