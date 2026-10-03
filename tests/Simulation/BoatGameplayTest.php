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
use Bedriox\Api\Entity\Value\BoatVariant;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\GameMode;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vehicle\BoatEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class BoatGameplayTest extends TestCase
{
    public function testFourAcceptedBareHandHitsDestroyABoatInSurvival(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player', 1)));
        $world->tick();
        $spawn = $world->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::BOAT,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ));
        self::assertInstanceOf(BoatEntity::class, $spawn->entity);

        for ($hit = 0; $hit < 4; ++$hit) {
            self::assertTrue($world->enqueue($factory->attack(
                'session',
                $spawn->entity->getRuntimeId(),
                0,
            )));
            $world->tick();
            if ($hit < 3) {
                self::assertTrue($spawn->entity->isAlive());
                for ($cooldown = 0; $cooldown < 10; ++$cooldown) {
                    $world->tick();
                }
            }
        }

        self::assertFalse($spawn->entity->isAlive());
    }

    public function testCreativeAttackDestroysChestBoatAndDropsBoatAndContents(): void
    {
        $factory = new SimulationCommandFactory();
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $world = new WorldSimulation(itemCatalog: $items);
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player', 1)));
        $world->tick();
        self::assertTrue($world->enqueue($factory->changeGameMode('session', GameMode::CREATIVE)));
        $world->tick();

        $spawn = $world->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CHEST_BOAT,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
            variant: BoatVariant::OAK->value,
        ));
        self::assertInstanceOf(BoatEntity::class, $spawn->entity);
        $spawn->entity->chestInventory()?->setStack(0, new ItemStack('minecraft:diamond', 3));

        self::assertTrue($world->enqueue($factory->attack(
            'session',
            $spawn->entity->getRuntimeId(),
            0,
        )));
        $events = [...$world->tick()->events, ...$world->tick()->events];

        self::assertFalse($spawn->entity->isAlive());
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityActorDied,
        ));
        $drops = array_values(array_map(
            static fn(ItemEntitySpawned $event): string => $event->entity->stack->identifier,
            array_filter($events, static fn(object $event): bool => $event instanceof ItemEntitySpawned),
        ));
        self::assertContains('minecraft:oak_chest_boat', $drops);
        self::assertContains('minecraft:diamond', $drops);
    }
}
