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
use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\EntityTypeRegistry;
use Bedriox\Server\Entity\Persistence\EntityPersistenceRecord;
use Bedriox\Server\Entity\Vanilla\BoggedEntity;
use Bedriox\Server\Entity\Vanilla\CaveSpiderEntity;
use Bedriox\Server\Entity\Vanilla\ChickenEntity;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\EndermanEntity;
use Bedriox\Server\Entity\Vanilla\EndermiteEntity;
use Bedriox\Server\Entity\Vanilla\HuskEntity;
use Bedriox\Server\Entity\Vanilla\MagmaCubeEntity;
use Bedriox\Server\Entity\Vanilla\ParchedEntity;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Entity\Vanilla\RabbitEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SilverfishEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Entity\Vanilla\SlimeEntity;
use Bedriox\Server\Entity\Vanilla\SpiderEntity;
use Bedriox\Server\Entity\Vanilla\StrayEntity;
use Bedriox\Server\Entity\Vanilla\WitchEntity;
use Bedriox\Server\Entity\Vanilla\WitherSkeletonEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Entity\Vanilla\ZombieVillagerEntity;
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

    /** @var array<string, EntityDefinition> */
    private static array $hostiles = [];

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

    public static function husk(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::HUSK, 0.6, 1.9, 20.0);
    }
    public static function zombieVillager(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::ZOMBIE_VILLAGER, 0.6, 1.95, 20.0, true);
    }
    public static function stray(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::STRAY, 0.6, 1.99, 20.0, true);
    }
    public static function bogged(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::BOGGED, 0.6, 1.99, 16.0, true);
    }
    public static function parched(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::PARCHED, 0.6, 1.99, 20.0);
    }
    public static function witherSkeleton(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::WITHER_SKELETON, 0.7, 2.4, 20.0);
    }
    public static function spider(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::SPIDER, 1.4, 0.9, 16.0);
    }
    public static function caveSpider(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::CAVE_SPIDER, 0.7, 0.5, 12.0);
    }
    public static function creeper(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::CREEPER, 0.6, 1.8, 20.0);
    }
    public static function enderman(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::ENDERMAN, 0.6, 2.9, 40.0);
    }
    public static function endermite(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::ENDERMITE, 0.4, 0.3, 8.0);
    }
    public static function silverfish(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::SILVERFISH, 0.4, 0.3, 8.0);
    }
    public static function witch(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::WITCH, 0.6, 1.9, 26.0);
    }

    public static function slime(SlimeSize $size = SlimeSize::LARGE): EntityDefinition
    {
        $dimension = 0.52 * $size->value;
        $health = $size->value ** 2;
        return self::hostile(VanillaEntityType::SLIME, $dimension, $dimension, (float) $health, key: 'slime:' . $size->value);
    }

    public static function magmaCube(SlimeSize $size = SlimeSize::LARGE): EntityDefinition
    {
        $width = 0.52 * $size->value;
        $height = match ($size) {
            SlimeSize::SMALL => 0.52, SlimeSize::MEDIUM => 1.02, SlimeSize::LARGE => 2.08,
        };
        $health = $size->value ** 2;
        return self::hostile(VanillaEntityType::MAGMA_CUBE, $width, $height, (float) $health, key: 'magma_cube:' . $size->value);
    }

    private static function hostile(VanillaEntityType $type, float $width, float $height, float $health, bool $burnsInDaylight = false, ?string $key = null): EntityDefinition
    {
        $key ??= $type->value;
        return self::$hostiles[$key] ??= new EntityDefinition($type, EntityCategory::MONSTER, $type->value, $width, $height, $health, burnsInDaylight: $burnsInDaylight);
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
            new RegisteredEntityDefinition(self::husk(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): HuskEntity => new HuskEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::zombieVillager(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): ZombieVillagerEntity => new ZombieVillagerEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::stray(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): StrayEntity => new StrayEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::bogged(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): BoggedEntity => new BoggedEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::parched(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): ParchedEntity => new ParchedEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::witherSkeleton(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): WitherSkeletonEntity => new WitherSkeletonEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::spider(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SpiderEntity => new SpiderEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::caveSpider(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): CaveSpiderEntity => new CaveSpiderEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::creeper(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): CreeperEntity => new CreeperEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(
                self::slime(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SlimeEntity => new SlimeEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
                persistenceFactory: static fn(string $uuid, int $runtimeId, EntityPersistenceRecord $record): SlimeEntity => new SlimeEntity(
                    $uuid,
                    $runtimeId,
                    $record->worldName(),
                    $record->position,
                    SlimeSize::tryFrom(is_int($record->variant) ? $record->variant : -1) ?? SlimeSize::LARGE,
                    yaw: $record->yaw,
                    pitch: $record->pitch,
                ),
            ),
            new RegisteredEntityDefinition(
                self::magmaCube(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): MagmaCubeEntity => new MagmaCubeEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
                persistenceFactory: static fn(string $uuid, int $runtimeId, EntityPersistenceRecord $record): MagmaCubeEntity => new MagmaCubeEntity(
                    $uuid,
                    $runtimeId,
                    $record->worldName(),
                    $record->position,
                    SlimeSize::tryFrom(is_int($record->variant) ? $record->variant : -1) ?? SlimeSize::LARGE,
                    yaw: $record->yaw,
                    pitch: $record->pitch,
                ),
            ),
            new RegisteredEntityDefinition(self::enderman(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): EndermanEntity => new EndermanEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::endermite(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): EndermiteEntity => new EndermiteEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::silverfish(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SilverfishEntity => new SilverfishEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::witch(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): WitchEntity => new WitchEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
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
