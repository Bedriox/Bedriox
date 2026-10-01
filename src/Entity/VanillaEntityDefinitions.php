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
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\EntityTypeRegistry;
use Bedriox\Server\Entity\Vanilla\ChickenEntity;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Entity\Vanilla\RabbitEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;

final class VanillaEntityDefinitions
{
    private static ?EntityDefinition $cow = null;
    private static ?EntityDefinition $chicken = null;
    private static ?EntityDefinition $pig = null;
    private static ?EntityDefinition $rabbit = null;
    private static ?EntityDefinition $sheep = null;
    private static ?EntityDefinition $skeleton = null;
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

    public static function chicken(): EntityDefinition
    {
        return self::$chicken ??= new EntityDefinition(
            VanillaEntityType::CHICKEN,
            EntityCategory::ANIMAL,
            VanillaEntityType::CHICKEN->value,
            0.6,
            0.8,
            4.0,
            gravity: 0.04,
        );
    }

    public static function pig(): EntityDefinition
    {
        return self::$pig ??= new EntityDefinition(
            VanillaEntityType::PIG,
            EntityCategory::ANIMAL,
            VanillaEntityType::PIG->value,
            0.9,
            0.9,
            10.0,
        );
    }

    public static function rabbit(): EntityDefinition
    {
        return self::$rabbit ??= new EntityDefinition(
            VanillaEntityType::RABBIT,
            EntityCategory::ANIMAL,
            VanillaEntityType::RABBIT->value,
            0.4,
            0.5,
            3.0,
        );
    }

    public static function sheep(): EntityDefinition
    {
        return self::$sheep ??= new EntityDefinition(
            VanillaEntityType::SHEEP,
            EntityCategory::ANIMAL,
            VanillaEntityType::SHEEP->value,
            0.9,
            1.3,
            8.0,
        );
    }

    public static function skeleton(): EntityDefinition
    {
        return self::$skeleton ??= new EntityDefinition(
            VanillaEntityType::SKELETON,
            EntityCategory::MONSTER,
            VanillaEntityType::SKELETON->value,
            0.6,
            1.99,
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
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): CowEntity =>
                    new CowEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
            new RegisteredEntityDefinition(
                self::chicken(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): ChickenEntity =>
                    new ChickenEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
            new RegisteredEntityDefinition(
                self::pig(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): PigEntity =>
                    new PigEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
            new RegisteredEntityDefinition(
                self::rabbit(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): RabbitEntity =>
                    new RabbitEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
            new RegisteredEntityDefinition(
                self::zombie(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): ZombieEntity =>
                    new ZombieEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
            new RegisteredEntityDefinition(
                self::sheep(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SheepEntity =>
                    new SheepEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
            new RegisteredEntityDefinition(
                self::skeleton(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SkeletonEntity =>
                    new SkeletonEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
            ),
        ];
    }

    /** @return list<RegisteredEntityDefinition> */
    public static function catalogRegistrations(EntityTypeRegistry $catalog): array
    {
        $implemented = [];
        foreach (self::registrations() as $registration) {
            $implemented[$registration->definition->networkIdentifier] = $registration;
        }
        $registrations = [];
        foreach ($catalog->definitions() as $network) {
            if (!$network->hasSpawnEgg()) {
                continue;
            }
            $registration = $implemented[$network->identifier()] ?? null;
            if ($registration !== null) {
                $registrations[] = $registration;
            }
        }

        return $registrations;
    }
}
