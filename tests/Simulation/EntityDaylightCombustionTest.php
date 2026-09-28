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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Entity\WorldEntityEnvironment;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class EntityDaylightCombustionTest extends TestCase
{
    public function testExposureDistinguishesDayNightShadeAndWater(): void
    {
        [$world, $states, $palette, $generation, $shapes] = self::world();
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000301',
            301,
            'world',
            new Position(0.5, 64.0, 0.5),
        );
        $environment = new WorldEntityEnvironment(
            $world,
            $states,
            $shapes,
            $palette->air,
            $generation->state('minecraft:water'),
        );

        self::assertTrue($environment->hasBurningDaylightExposure($zombie));

        $world->setTime(14_000);
        self::assertFalse($environment->hasBurningDaylightExposure($zombie));

        $world->setTime(0);
        $world->setBlockState(0, 66, 0, $generation->state('minecraft:stone'));
        self::assertFalse($environment->hasBurningDaylightExposure($zombie));

        $world->setBlockState(0, 66, 0, $palette->air);
        $world->setBlockState(0, 64, 0, $generation->state('minecraft:water'));
        self::assertTrue($environment->isTouchingWater($zombie));
        self::assertFalse($environment->hasBurningDaylightExposure($zombie));
    }

    public function testDaylightFireDamagesAndWaterExtinguishesAuthoritatively(): void
    {
        [$world, $states, $palette, $generation, $shapes] = self::world();
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            waterState: $generation->state('minecraft:water'),
            lavaState: $generation->state('minecraft:lava'),
            blockStateRegistry: $states,
            blockCollisionRegistry: $shapes,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(ZombieEntity::class, $spawn->entity);
        $zombie = $spawn->entity;

        $events = [];
        for ($tick = 1; $tick <= 20; ++$tick) {
            array_push($events, ...$simulation->tick()->events);
        }
        self::assertTrue($zombie->isOnFire());
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityActorMetadataChanged,
        ));

        $events = [];
        for ($tick = 21; $tick <= 40; ++$tick) {
            array_push($events, ...$simulation->tick()->events);
        }
        self::assertSame(19.0, $zombie->getHealth());
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityActorDamaged,
        ));

        $world->setBlockState(0, 64, 0, $generation->state('minecraft:water'));
        $events = $simulation->tick()->events;
        self::assertFalse($zombie->isOnFire());
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityActorMetadataChanged,
        ));
    }

    public function testHelmetPreventsDaylightCombustionAndTakesDurabilityDamage(): void
    {
        [$world, $states, $palette, $generation, $shapes] = self::world();
        $data = BedrockDataSet::bundled();
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocks,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            waterState: $generation->state('minecraft:water'),
            lavaState: $generation->state('minecraft:lava'),
            itemCatalog: $items,
            blockCatalog: $blocks,
            blockStateRegistry: $states,
            blockCollisionRegistry: $shapes,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(ZombieEntity::class, $spawn->entity);
        $zombie = $spawn->entity;
        $zombie->getController()->equipment()->setItem(
            EquipmentSlot::HEAD,
            new ItemStack('minecraft:iron_helmet', 1),
        );

        for ($tick = 1; $tick <= WorldEntityEnvironment::DAYLIGHT_CHECK_INTERVAL_TICKS; ++$tick) {
            $simulation->tick();
        }

        self::assertFalse($zombie->isOnFire());
        self::assertSame(1, $zombie->equipmentState()->getItem(EquipmentSlot::HEAD)?->damage);
    }

    /** @return array{World, BlockStateRegistry, FixedFlatBlockPalette, GenerationBlockPalette, BlockCollisionRegistry} */
    private static function world(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('world', 12345),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->chunk(new ChunkPosition(0, 0));
        $shapes = BlockCollisionRegistry::forGenerationPalette($states, $generation);

        return [$world, $states, $palette, $generation, $shapes];
    }
}
