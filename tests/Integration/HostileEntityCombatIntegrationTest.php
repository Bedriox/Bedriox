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

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Api\Player\GameMode;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\Goal\MeleeAttackIntentGoal;
use Bedriox\Server\Entity\Ai\Sensor\NearestPlayerSensor;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityWorldRuntime;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Event\EntityActorAttackStarted;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class HostileEntityCombatIntegrationTest extends TestCase
{
    public function testWallBlocksHostileTargetingAndMeleeDamage(): void
    {
        [$simulation, $blocks, $palette] = self::simulation();
        $blocks->setBlockState(0, 64, 1, $palette->grassBlock);
        $blocks->setBlockState(0, 65, 1, $palette->grassBlock);

        $events = self::joinAndTick($simulation, GameMode::SURVIVAL);

        self::assertSame(20.0, $simulation->snapshot()->players[0]->health);
        self::assertSame([], self::events($events, PlayerDamaged::class));
        self::assertSame([], self::events($events, PlayerKnockedBack::class));
        self::assertSame([], self::events($events, EntityActorAttackStarted::class));
    }

    public function testHostileAiExcludesCreativeAndSpectatorTargets(): void
    {
        foreach ([GameMode::CREATIVE, GameMode::SPECTATOR] as $gameMode) {
            [$simulation] = self::simulation();

            $events = self::joinAndTick($simulation, $gameMode);

            self::assertSame(20.0, $simulation->snapshot()->players[0]->health, $gameMode->value);
            self::assertSame([], self::events($events, PlayerDamaged::class), $gameMode->value);
            self::assertSame([], self::events($events, PlayerKnockedBack::class), $gameMode->value);
        }
    }

    public function testHostileMeleeAppliesAuthoritativeKnockbackAndVisibleAttackState(): void
    {
        [$simulation] = self::simulation();

        $events = self::joinAndTick($simulation, GameMode::SURVIVAL);
        $damage = self::events($events, PlayerDamaged::class);
        $knockback = self::events($events, PlayerKnockedBack::class);
        $attacks = self::events($events, EntityActorAttackStarted::class);

        self::assertCount(1, $damage);
        self::assertSame(3.0, $damage[0]->damage);
        self::assertCount(1, $knockback);
        self::assertSame(0.0, $knockback[0]->motionX);
        self::assertSame(0.4, $knockback[0]->motionY);
        self::assertSame(-0.4, $knockback[0]->motionZ);
        self::assertCount(1, $attacks);
        self::assertSame(100, $attacks[0]->entity->getRuntimeId());
    }

    public function testHostileMeleeDeathNamesTheMobWithoutAssigningAPlayerKiller(): void
    {
        [$simulation] = self::simulation();

        $events = self::joinAndTick($simulation, GameMode::SURVIVAL, 3.0);
        $deaths = self::events($events, PlayerDied::class);

        self::assertCount(1, $deaths);
        self::assertNull($deaths[0]->killer);
        self::assertInstanceOf(TranslatableMessage::class, $deaths[0]->deathMessage);
        self::assertSame('death.attack.mob', $deaths[0]->deathMessage->key);
        self::assertSame(['Target', 'Zombie'], $deaths[0]->deathMessage->parameters);
    }

    /** @return array{WorldSimulation, World, FixedFlatBlockPalette} */
    private static function simulation(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $blocks = new World(
            new WorldMetadata('world', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $blocks->chunk(new ChunkPosition(0, 0));
        $entities = new EntityRegistry(firstRuntimeId: 100);
        $behavior = new AiBehaviorDefinition(
            sensors: [new NearestPlayerSensor('bedriox:test_hostile_target', 1, 8.0, 2)],
            goals: [new MeleeAttackIntentGoal('bedriox:test_hostile_melee', 100, 4.0, 20, 3.0)],
        );
        $entities->spawn(static fn(string $uuid, int $runtimeId): ZombieEntity => new ZombieEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(0.5, 64.0, 2.5),
            $behavior,
        ));
        $runtime = new EntityWorldRuntime(
            $entities,
            new EntitySpawnService($entities, EntityDefinitionRegistry::baseline()),
        );

        return [new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $palette,
            entityRuntime: $runtime,
            entityAiEnabled: true,
            spawnAnimals: false,
            spawnMonsters: false,
        ), $blocks, $palette];
    }

    /** @return list<object> */
    private static function joinAndTick(
        WorldSimulation $simulation,
        GameMode $gameMode,
        float $health = 20.0,
    ): array {
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('target-identity', 'Target'),
            'world',
            new Position(0.5, 64.0, 0.5),
            0.0,
            0.0,
            new PlayerInventoryState([], 0),
            1,
            1,
            $gameMode->value,
            health: $health,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join(
            'target-session',
            'target-identity',
            'Target',
            1,
            $bootstrap,
        )));

        return $simulation->tick()->events;
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return list<T>
     */
    private static function events(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn(object $event): bool => $event instanceof $type));
    }
}
