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

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\DimensionType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\SimulationLimits;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\DimensionTypeBounds;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorldBoundarySimulationTest extends TestCase
{
    public function testSpectatorCanCrossTheOverworldTerrainFloorWithoutCorrection(): void
    {
        $commands = new SimulationCommandFactory();
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $blocks = new World(
            new WorldMetadata('spectator-boundary-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $simulation = new WorldSimulation(
            spawn: new Position(0.0, -62.29, 0.0),
            blockWorld: $blocks,
            blockPalette: $palette,
            blockStateRegistry: $states,
        );
        self::assertTrue($simulation->enqueue($commands->join('spectator', 'spectator-identity', 'Spectator')));
        self::assertTrue($simulation->enqueue($commands->changeGameMode('spectator', GameMode::SPECTATOR)));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->move(
            'spectator',
            1,
            0.0,
            -65.0,
            0.0,
            0.0,
            90.0,
            MovementMode::WALKING,
        )));
        $events = $simulation->tick()->events;

        $movement = self::firstEvent($events, PlayerMoved::class);
        self::assertInstanceOf(PlayerMoved::class, $movement);
        self::assertSame(-65.0, $movement->player->position->y);
        self::assertSame(20.0, $movement->player->health);

        self::assertTrue($simulation->enqueue($commands->move(
            'spectator',
            2,
            0.0,
            -66.0,
            0.0,
            0.0,
            90.0,
            MovementMode::WALKING,
            flying: false,
        )));
        $belowFloorEvents = $simulation->tick()->events;
        $belowFloorMovement = self::firstEvent($belowFloorEvents, PlayerMoved::class);
        self::assertInstanceOf(PlayerMoved::class, $belowFloorMovement);
        self::assertSame(-66.0, $belowFloorMovement->player->position->y);
        self::assertSame(20.0, $belowFloorMovement->player->health);
    }

    /** @return iterable<string, array{DimensionType}> */
    public static function dimensionProvider(): iterable
    {
        yield 'overworld' => [DimensionType::OVERWORLD];
        yield 'nether' => [DimensionType::NETHER];
        yield 'end' => [DimensionType::END];
    }

    #[DataProvider('dimensionProvider')]
    public function testVoidDamageUsesTheCurrentDimensionFloor(DimensionType $dimension): void
    {
        $spawnY = (float) (DimensionTypeBounds::minimumY($dimension) - 16);
        $commands = new SimulationCommandFactory();
        $limits = new SimulationLimits(flatGroundY: $spawnY);
        $simulation = new WorldSimulation(
            limits: $limits,
            spawn: new Position(0.0, $spawnY, 0.0),
            dimension: $dimension,
        );
        self::assertTrue($simulation->enqueue($commands->join('survival', 'survival-identity', 'Survival')));

        $firstTick = $simulation->tick()->events;
        $damage = self::firstEvent($firstTick, PlayerDamaged::class);
        self::assertInstanceOf(PlayerDamaged::class, $damage);
        self::assertSame(DamageCause::Void, $damage->cause);
        self::assertSame(10.0, $damage->damage);
        self::assertSame(10.0, $damage->player->health);

        $death = null;
        for ($tick = 0; $tick < 20 && $death === null; ++$tick) {
            $death = self::firstEvent($simulation->tick()->events, PlayerDied::class);
        }
        self::assertInstanceOf(PlayerDied::class, $death);
        self::assertSame(DamageCause::Void, $death->cause);
        self::assertInstanceOf(TranslatableMessage::class, $death->deathMessage);
        self::assertSame('death.attack.outOfWorld', $death->deathMessage->key);
    }

    /** @return iterable<string, array{GameMode}> */
    public static function invulnerableGameModeProvider(): iterable
    {
        yield 'creative' => [GameMode::CREATIVE];
        yield 'spectator' => [GameMode::SPECTATOR];
    }

    #[DataProvider('invulnerableGameModeProvider')]
    public function testCreativeAndSpectatorDoNotTakeVoidDamage(GameMode $gameMode): void
    {
        $commands = new SimulationCommandFactory();
        $simulation = new WorldSimulation(
            limits: new SimulationLimits(flatGroundY: -80.0),
            spawn: new Position(0.0, -80.0, 0.0),
        );
        self::assertTrue($simulation->enqueue($commands->join('player', 'player-identity', 'Player')));
        self::assertTrue($simulation->enqueue($commands->changeGameMode('player', $gameMode)));

        $events = $simulation->tick()->events;
        self::assertNull(self::firstEvent($events, PlayerDamaged::class));
        self::assertSame(20.0, $simulation->snapshot()->players[0]->health);
        self::assertTrue($simulation->snapshot()->players[0]->alive);
    }

    public function testAdventurePlayersTakeVoidDamage(): void
    {
        $commands = new SimulationCommandFactory();
        $simulation = new WorldSimulation(
            limits: new SimulationLimits(flatGroundY: -80.0),
            spawn: new Position(0.0, -80.0, 0.0),
        );
        self::assertTrue($simulation->enqueue($commands->join('adventure', 'adventure-identity', 'Adventure')));
        self::assertTrue($simulation->enqueue($commands->changeGameMode('adventure', GameMode::ADVENTURE)));
        self::assertTrue($simulation->enqueue($commands->addPlayerEffect(
            'adventure',
            new EffectInstance(EffectType::RESISTANCE, 200, amplifier: 4),
            EffectCause::COMMAND,
        )));

        $damage = self::firstEvent($simulation->tick()->events, PlayerDamaged::class);
        self::assertInstanceOf(PlayerDamaged::class, $damage);
        self::assertSame(DamageCause::Void, $damage->cause);
        self::assertSame(10.0, $damage->damage);
    }

    /** @template T of object
     * @param list<object> $events
     * @param class-string<T> $class
     * @return T|null
     */
    private static function firstEvent(array $events, string $class): ?object
    {
        foreach ($events as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        return null;
    }
}
