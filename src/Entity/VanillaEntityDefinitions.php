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
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Data\EntityTypeRegistry;
use Bedriox\Server\Entity\Block\FallingBlockEntity;
use Bedriox\Server\Entity\Persistence\EntityPersistenceRecord;
use Bedriox\Server\Entity\Vanilla\AxolotlEntity;
use Bedriox\Server\Entity\Vanilla\BoggedEntity;
use Bedriox\Server\Entity\Vanilla\CaveSpiderEntity;
use Bedriox\Server\Entity\Vanilla\ChickenEntity;
use Bedriox\Server\Entity\Vanilla\CodEntity;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\DolphinEntity;
use Bedriox\Server\Entity\Vanilla\DrownedEntity;
use Bedriox\Server\Entity\Vanilla\End\EndCrystalEntity;
use Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity;
use Bedriox\Server\Entity\Vanilla\End\ShulkerEntity;
use Bedriox\Server\Entity\Vanilla\EndermanEntity;
use Bedriox\Server\Entity\Vanilla\EndermiteEntity;
use Bedriox\Server\Entity\Vanilla\ExpandedLandEntityRegistrations;
use Bedriox\Server\Entity\Vanilla\GlowSquidEntity;
use Bedriox\Server\Entity\Vanilla\GuardianEntity;
use Bedriox\Server\Entity\Vanilla\HuskEntity;
use Bedriox\Server\Entity\Vanilla\MagmaCubeEntity;
use Bedriox\Server\Entity\Vanilla\Misc\LeashKnotEntity;
use Bedriox\Server\Entity\Vanilla\Nether\NetherEntityRegistrations;
use Bedriox\Server\Entity\Vanilla\ParchedEntity;
use Bedriox\Server\Entity\Vanilla\PhantomEntity;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Entity\Vanilla\PufferfishEntity;
use Bedriox\Server\Entity\Vanilla\RabbitEntity;
use Bedriox\Server\Entity\Vanilla\SalmonEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SilverfishEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Entity\Vanilla\SlimeEntity;
use Bedriox\Server\Entity\Vanilla\SpiderEntity;
use Bedriox\Server\Entity\Vanilla\SquidEntity;
use Bedriox\Server\Entity\Vanilla\StrayEntity;
use Bedriox\Server\Entity\Vanilla\TropicalFishEntity;
use Bedriox\Server\Entity\Vanilla\TurtleEntity;
use Bedriox\Server\Entity\Vanilla\WitchEntity;
use Bedriox\Server\Entity\Vanilla\WitherSkeletonEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Entity\Vanilla\ZombieVillagerEntity;
use Bedriox\Server\Entity\Vehicle\BoatEntity;
use Bedriox\Server\Entity\Vehicle\VehicleEntityDefinitions;
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
    private static ?EntityDefinition $fallingBlock = null;

    /** @var array<string, EntityDefinition> */
    private static array $hostiles = [];

    /** @var array<string, EntityDefinition> */
    private static array $aquatic = [];

    /** @var array<string, EntityDefinition> */
    private static array $passive = [];

    public static function fallingBlock(): EntityDefinition
    {
        return self::$fallingBlock ??= new EntityDefinition(
            VanillaEntityType::FALLING_BLOCK,
            EntityCategory::MISCELLANEOUS,
            VanillaEntityType::FALLING_BLOCK->value,
            0.98,
            0.98,
            1.0,
            gravity: 0.04,
            drag: 0.02,
        );
    }

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
    public static function phantom(): EntityDefinition
    {
        return self::$hostiles[VanillaEntityType::PHANTOM->value] ??= new EntityDefinition(
            VanillaEntityType::PHANTOM,
            EntityCategory::MONSTER,
            VanillaEntityType::PHANTOM->value,
            0.9,
            0.5,
            20.0,
            gravity: 0.0,
            drag: 0.05,
            burnsInDaylight: true,
        );
    }
    public static function enderman(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::ENDERMAN, 0.6, 2.9, 40.0);
    }
    public static function endermite(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::ENDERMITE, 0.4, 0.3, 8.0);
    }

    public static function enderDragon(): EntityDefinition
    {
        return self::flyingHostile(VanillaEntityType::ENDER_DRAGON, 16.0, 8.0, 200.0);
    }

    public static function endCrystal(): EntityDefinition
    {
        return self::$passive[VanillaEntityType::ENDER_CRYSTAL->value] ??= new EntityDefinition(
            VanillaEntityType::ENDER_CRYSTAL,
            EntityCategory::MISCELLANEOUS,
            VanillaEntityType::ENDER_CRYSTAL->value,
            2.0,
            2.0,
            1.0,
            gravity: 0.0,
        );
    }

    public static function leashKnot(): EntityDefinition
    {
        return self::$passive[VanillaEntityType::LEASH_KNOT->value] ??= new EntityDefinition(
            VanillaEntityType::LEASH_KNOT,
            EntityCategory::MISCELLANEOUS,
            VanillaEntityType::LEASH_KNOT->value,
            0.375,
            0.5,
            1.0,
            gravity: 0.0,
        );
    }

    public static function shulker(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::SHULKER, 1.0, 1.0, 30.0);
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

    public static function blaze(): EntityDefinition
    {
        return self::flyingHostile(VanillaEntityType::BLAZE, 0.6, 1.8, 20.0);
    }

    public static function ghast(): EntityDefinition
    {
        return self::flyingHostile(VanillaEntityType::GHAST, 4.0, 4.0, 10.0);
    }

    public static function happyGhast(): EntityDefinition
    {
        return self::$passive[VanillaEntityType::HAPPY_GHAST->value] ??= new EntityDefinition(VanillaEntityType::HAPPY_GHAST, EntityCategory::ANIMAL, VanillaEntityType::HAPPY_GHAST->value, 4.0, 4.0, 20.0, gravity: 0.0, drag: 0.05);
    }

    public static function hoglin(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::HOGLIN, 1.4, 1.4, 40.0);
    }

    public static function piglin(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::PIGLIN, 0.6, 1.9, 16.0);
    }

    public static function piglinBrute(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::PIGLIN_BRUTE, 0.6, 1.9, 50.0);
    }

    public static function strider(): EntityDefinition
    {
        return self::$passive[VanillaEntityType::STRIDER->value] ??= new EntityDefinition(VanillaEntityType::STRIDER, EntityCategory::ANIMAL, VanillaEntityType::STRIDER->value, 0.9, 1.7, 20.0);
    }

    public static function zoglin(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::ZOGLIN, 1.4, 1.4, 40.0);
    }

    public static function zombifiedPiglin(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::ZOMBIFIED_PIGLIN, 0.6, 1.95, 20.0);
    }

    public static function cod(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::COD, 0.5, 0.3, 3.0);
    }
    public static function salmon(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::SALMON, 0.7, 0.4, 3.0);
    }
    public static function tropicalFish(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::TROPICAL_FISH, 0.5, 0.4, 3.0);
    }
    public static function pufferfish(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::PUFFERFISH, 0.7, 0.7, 3.0);
    }
    public static function squid(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::SQUID, 0.8, 0.8, 10.0);
    }
    public static function glowSquid(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::GLOW_SQUID, 0.8, 0.8, 10.0);
    }
    public static function dolphin(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::DOLPHIN, 0.9, 0.6, 10.0);
    }
    public static function turtle(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::TURTLE, 1.2, 0.4, 30.0);
    }
    public static function axolotl(): EntityDefinition
    {
        return self::aquatic(VanillaEntityType::AXOLOTL, 0.75, 0.42, 14.0);
    }
    public static function drowned(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::DROWNED, 0.6, 1.95, 20.0);
    }
    public static function guardian(): EntityDefinition
    {
        return self::hostile(VanillaEntityType::GUARDIAN, 0.85, 0.85, 30.0);
    }

    private static function aquatic(VanillaEntityType $type, float $width, float $height, float $health): EntityDefinition
    {
        return self::$aquatic[$type->value] ??= new EntityDefinition(
            $type,
            EntityCategory::WATER,
            $type->value,
            $width,
            $height,
            $health,
            gravity: 0.02,
            drag: 0.1,
        );
    }

    private static function hostile(VanillaEntityType $type, float $width, float $height, float $health, bool $burnsInDaylight = false, ?string $key = null): EntityDefinition
    {
        $key ??= $type->value;
        return self::$hostiles[$key] ??= new EntityDefinition($type, EntityCategory::MONSTER, $type->value, $width, $height, $health, burnsInDaylight: $burnsInDaylight);
    }

    private static function flyingHostile(VanillaEntityType $type, float $width, float $height, float $health): EntityDefinition
    {
        return self::$hostiles[$type->value] ??= new EntityDefinition(
            $type,
            EntityCategory::MONSTER,
            $type->value,
            $width,
            $height,
            $health,
            gravity: 0.0,
            drag: 0.05,
        );
    }

    /** @return list<RegisteredEntityDefinition> */
    public static function registrations(): array
    {
        return [
            new RegisteredEntityDefinition(
                self::fallingBlock(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): FallingBlockEntity =>
                    new FallingBlockEntity(
                        $uuid,
                        $runtimeId,
                        self::fallingBlock(),
                        $world,
                        $position,
                        CanonicalBlockState::from('minecraft:sand'),
                    ),
                persistenceFactory: static fn(string $uuid, int $runtimeId, EntityPersistenceRecord $record): FallingBlockEntity =>
                    new FallingBlockEntity(
                        $uuid,
                        $runtimeId,
                        self::fallingBlock(),
                        $record->worldName(),
                        $record->position,
                        CanonicalBlockState::from('minecraft:sand'),
                        motion: $record->motion,
                    ),
            ),
            new RegisteredEntityDefinition(
                VehicleEntityDefinitions::boat(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): BoatEntity =>
                    new BoatEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
                persistenceFactory: static fn(string $uuid, int $runtimeId, EntityPersistenceRecord $record): BoatEntity =>
                    new BoatEntity(
                        $uuid,
                        $runtimeId,
                        $record->worldName(),
                        $record->position,
                        \Bedriox\Api\Entity\Value\BoatVariant::tryFrom(is_int($record->variant) ? $record->variant : -1)
                            ?? \Bedriox\Api\Entity\Value\BoatVariant::OAK,
                        false,
                        $record->motion,
                        $record->yaw,
                        $record->pitch,
                        $record->health,
                    ),
            ),
            new RegisteredEntityDefinition(
                VehicleEntityDefinitions::chestBoat(),
                static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): BoatEntity =>
                    new BoatEntity($uuid, $runtimeId, $world, $position, chestBoat: true, yaw: $yaw, pitch: $pitch),
                persistenceFactory: static fn(string $uuid, int $runtimeId, EntityPersistenceRecord $record): BoatEntity =>
                    new BoatEntity(
                        $uuid,
                        $runtimeId,
                        $record->worldName(),
                        $record->position,
                        \Bedriox\Api\Entity\Value\BoatVariant::tryFrom(is_int($record->variant) ? $record->variant : -1)
                            ?? \Bedriox\Api\Entity\Value\BoatVariant::OAK,
                        true,
                        $record->motion,
                        $record->yaw,
                        $record->pitch,
                        $record->health,
                    ),
            ),
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
            new RegisteredEntityDefinition(self::phantom(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): PhantomEntity => new PhantomEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
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
                variantFactory: static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch, int|string $variant): MagmaCubeEntity => new MagmaCubeEntity(
                    $uuid,
                    $runtimeId,
                    $world,
                    $position,
                    is_int($variant) ? SlimeSize::tryFrom($variant) ?? SlimeSize::LARGE : SlimeSize::LARGE,
                    yaw: $yaw,
                    pitch: $pitch,
                ),
            ),
            new RegisteredEntityDefinition(self::enderman(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): EndermanEntity => new EndermanEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::endermite(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): EndermiteEntity => new EndermiteEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::enderDragon(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): EnderDragonEntity => new EnderDragonEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::endCrystal(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): EndCrystalEntity => new EndCrystalEntity($uuid, $runtimeId, $world, $position)),
            new RegisteredEntityDefinition(self::leashKnot(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): LeashKnotEntity => new LeashKnotEntity($uuid, $runtimeId, $world, $position)),
            new RegisteredEntityDefinition(self::shulker(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): ShulkerEntity => new ShulkerEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::silverfish(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SilverfishEntity => new SilverfishEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::witch(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): WitchEntity => new WitchEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::cod(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): CodEntity => new CodEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::salmon(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SalmonEntity => new SalmonEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::tropicalFish(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): TropicalFishEntity => new TropicalFishEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::pufferfish(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): PufferfishEntity => new PufferfishEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::squid(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): SquidEntity => new SquidEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::glowSquid(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): GlowSquidEntity => new GlowSquidEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::dolphin(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): DolphinEntity => new DolphinEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::turtle(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): TurtleEntity => new TurtleEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::axolotl(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): AxolotlEntity => new AxolotlEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::drowned(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): DrownedEntity => new DrownedEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            new RegisteredEntityDefinition(self::guardian(), static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): GuardianEntity => new GuardianEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch)),
            ...ExpandedLandEntityRegistrations::all(),
            ...NetherEntityRegistrations::all(),
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
            $registration = $implemented[$network->identifier()] ?? null;
            if ($registration !== null) {
                $registrations[] = $registration;
            }
        }

        return $registrations;
    }
}
