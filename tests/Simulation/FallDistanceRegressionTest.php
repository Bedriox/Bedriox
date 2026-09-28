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
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class FallDistanceRegressionTest extends TestCase
{
    public function testWalkingOffOneBlockLedgeAndLandingDoesNotCauseFallDamage(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('one-block-descent-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $blocks->setBlockState(0, 64, 0, $palette->grassBlock);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(
            spawn: new Position(0.5, 65.0, 0.5),
            blockWorld: $blocks,
            blockPalette: $palette,
        );
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            1.5,
            65.0,
            0.5,
            0.0,
            0.0,
            MovementMode::WALKING,
        )));
        $leftLedge = $world->tick()->events;
        self::assertCount(1, $leftLedge);
        self::assertInstanceOf(PlayerMoved::class, $leftLedge[0]);
        self::assertSame(VerticalState::AIRBORNE, $leftLedge[0]->player->verticalState);

        self::assertTrue($world->enqueue($factory->move(
            'session',
            2,
            1.5,
            64.0,
            0.5,
            0.0,
            0.0,
            MovementMode::WALKING,
            deltaY: -1.0,
        )));
        $landed = $world->tick()->events;

        self::assertCount(1, $landed);
        self::assertInstanceOf(PlayerMoved::class, $landed[0]);
        self::assertSame(VerticalState::GROUNDED, $landed[0]->player->verticalState);
        self::assertSame(20.0, $world->snapshot()->players[0]->health);
    }

    public function testThreeBlockFallIsSafeAndFourBlockFallCausesOneDamage(): void
    {
        [$safeWorld, $safeEvents] = $this->landFrom(67.0);
        self::assertCount(1, $safeEvents);
        self::assertInstanceOf(PlayerMoved::class, $safeEvents[0]);
        self::assertSame(20.0, $safeWorld->snapshot()->players[0]->health);

        [$damagedWorld, $damagedEvents] = $this->landFrom(68.0);
        self::assertCount(2, $damagedEvents);
        self::assertInstanceOf(PlayerMoved::class, $damagedEvents[0]);
        self::assertInstanceOf(PlayerDamaged::class, $damagedEvents[1]);
        self::assertSame(1.0, $damagedEvents[1]->damage);
        self::assertSame(19.0, $damagedWorld->snapshot()->players[0]->health);
    }

    public function testRejectedMovementDoesNotChangeAccumulatedFallDistance(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(spawn: new Position(0.0, 68.0, 0.0));
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            0.0,
            66.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -2.0,
        )));
        self::assertInstanceOf(PlayerMoved::class, $world->tick()->events[0]);

        self::assertTrue($world->enqueue($factory->move(
            'session',
            2,
            0.0,
            60.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -6.0,
        )));
        $rejected = $world->tick()->events[0];
        self::assertInstanceOf(MovementCorrected::class, $rejected);
        self::assertSame('terrain_collision', $rejected->reason);

        self::assertTrue($world->enqueue($factory->move(
            'session',
            3,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -2.0,
        )));
        $landed = $world->tick()->events;

        self::assertCount(2, $landed);
        self::assertInstanceOf(PlayerDamaged::class, $landed[1]);
        self::assertSame(1.0, $landed[1]->damage);
        self::assertSame(19.0, $world->snapshot()->players[0]->health);
    }

    public function testJumpAndLandingDoNotCauseFallDamage(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
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
            MovementMode::JUMPING,
            jumpRequested: true,
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'session',
            2,
            0.0,
            64.42,
            0.0,
            0.0,
            0.0,
            MovementMode::JUMPING,
            deltaY: 0.34,
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'session',
            3,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -0.42,
        )));
        $landed = $world->tick()->events;

        self::assertCount(1, $landed);
        self::assertInstanceOf(PlayerMoved::class, $landed[0]);
        self::assertSame(VerticalState::GROUNDED, $landed[0]->player->verticalState);
        self::assertSame(20.0, $world->snapshot()->players[0]->health);
    }

    public function testDeathAndRespawnDiscardPreviouslyAccumulatedFallDistance(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(spawn: new Position(0.0, 68.0, 0.0));
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            0.0,
            66.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -2.0,
        )));
        $world->tick();

        self::assertTrue($world->enqueue($factory->damage('session', 20.0)));
        $world->tick();
        self::assertTrue($world->enqueue($factory->acknowledgeRespawn('session')));
        $world->tick();
        for ($tick = 0; $tick < 61; ++$tick) {
            $world->tick();
        }

        self::assertTrue($world->enqueue($factory->move(
            'session',
            2,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -4.0,
        )));
        $landed = $world->tick()->events;

        self::assertCount(2, $landed);
        self::assertInstanceOf(PlayerDamaged::class, $landed[1]);
        self::assertSame(1.0, $landed[1]->damage);
        self::assertSame(19.0, $world->snapshot()->players[0]->health);
    }

    public function testPluginTeleportResetsPreviouslyAccumulatedFallDistance(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(spawn: new Position(0.0, 68.0, 0.0));
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            0.0,
            66.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -2.0,
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->teleport('session', 0.0, 68.0, 0.0)));
        $world->tick();

        self::assertTrue($world->enqueue($factory->move(
            'session',
            2,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -4.0,
        )));
        $landed = $world->tick()->events;

        self::assertCount(2, $landed);
        self::assertInstanceOf(PlayerDamaged::class, $landed[1]);
        self::assertSame(1.0, $landed[1]->damage);
        self::assertSame(19.0, $world->snapshot()->players[0]->health);
    }

    /** @return array{WorldSimulation, list<object>} */
    private function landFrom(float $spawnY): array
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(spawn: new Position(0.0, $spawnY, 0.0));
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
            deltaY: 64.0 - $spawnY,
        )));

        return [$world, $world->tick()->events];
    }
}
