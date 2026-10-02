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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;

final class PlayerLifecycleTest extends TestCase
{
    public function testDeathDropsAndClearsCarriedInventoryBeforeRespawn(): void
    {
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
        $items = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $world = new WorldSimulation(blockPalette: $palette, itemEntities: $items);
        $factory = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('12345678-1234-5678-9abc-123456789abc', 'Player'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:grass_block', 3)),
            ], 0),
            1,
            1,
        );
        self::assertTrue($world->enqueue($factory->join(
            'session',
            $bootstrap->identity->uuid,
            $bootstrap->identity->displayName,
            bootstrap: $bootstrap,
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->damage('session', 20.0)));

        $world->tick();

        self::assertSame(1, $items->count());
        self::assertSame('minecraft:grass_block', $items->all()[0]->stack->identifier);
        self::assertSame(3, $items->all()[0]->stack->count);

        self::assertTrue($world->enqueue($factory->acknowledgeRespawn('session')));
        $events = $world->tick()->events;
        $respawned = array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof PlayerRespawned,
        ));
        self::assertCount(1, $respawned);
        self::assertSame(array_fill(0, 36, null), $respawned[0]->inventory);
        self::assertSame([], $respawned[0]->armor);
        self::assertNull($respawned[0]->offhand);
    }

    public function testAuthoritativeLandingAppliesPmmpStyleFallDamage(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(spawn: new Position(0.0, 70.0, 0.0));
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -6.0,
        )));

        $events = $world->tick()->events;

        self::assertCount(2, $events);
        self::assertInstanceOf(PlayerMoved::class, $events[0]);
        self::assertInstanceOf(PlayerDamaged::class, $events[1]);
        self::assertSame(DamageCause::Fall, $events[1]->cause);
        self::assertSame(3.0, $events[1]->damage);
        self::assertSame(17.0, $world->snapshot()->players[0]->health);
    }

    public function testDeathRejectsGameplayAndRespawnRestoresAuthoritativeState(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->damage('session', 20.0)));

        $death = $world->tick()->events;

        self::assertCount(2, $death);
        self::assertInstanceOf(PlayerDamaged::class, $death[0]);
        self::assertInstanceOf(PlayerDied::class, $death[1]);
        self::assertFalse($world->snapshot()->players[0]->alive);
        self::assertSame(0.0, $world->snapshot()->players[0]->health);

        self::assertTrue($world->enqueue($factory->chat('session', 1, 'not while dead')));
        self::assertInstanceOf(CommandRejected::class, $world->tick()->events[0]);
        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            1.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
        )));
        self::assertInstanceOf(MovementCorrected::class, $world->tick()->events[0]);

        self::assertTrue($world->enqueue($factory->acknowledgeRespawn('session')));
        $handshake = $world->tick()->events;
        self::assertInstanceOf(RespawnAcknowledged::class, $handshake[0]);
        self::assertInstanceOf(PlayerRespawned::class, $handshake[1]);
        $respawned = $world->snapshot()->players[0];
        self::assertTrue($respawned->alive);
        self::assertSame(20.0, $respawned->health);
        self::assertEquals(new Position(0.0, 64.0, 0.0), $respawned->position);

        self::assertTrue($world->enqueue($factory->damage('session', 5.0)));
        $protected = $world->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $protected);
        self::assertSame('damage_cooldown', $protected->reason);
        self::assertSame(20.0, $world->snapshot()->players[0]->health);
    }

    public function testRespawnClearsAuthoritativeFireState(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('12345678-1234-5678-9abc-123456789abc', 'Player'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([], 0),
            1,
            1,
            health: 1.0,
            fireTicks: 100,
        );
        $world->enqueue($factory->join(
            'session',
            $bootstrap->identity->uuid,
            $bootstrap->identity->displayName,
            bootstrap: $bootstrap,
        ));
        $world->tick();
        $world->enqueue($factory->damage('session', 1.0, DamageCause::Fire));
        $world->tick();
        self::assertGreaterThan(0, $world->snapshot()->players[0]->fireTicks);

        $world->enqueue($factory->acknowledgeRespawn('session'));
        $world->tick();

        self::assertTrue($world->snapshot()->players[0]->alive);
        self::assertSame(0, $world->snapshot()->players[0]->fireTicks);
    }
}
