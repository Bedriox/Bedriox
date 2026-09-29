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
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerEffectChanged;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class PlayerEffectSimulationTest extends TestCase
{
    public function testPluginEffectMutationIsAuthoritativeAndTicks(): void
    {
        $world = self::joinedWorld();
        self::assertTrue($world->enqueuePluginEffect(
            'identity-one',
            new EffectInstance(EffectType::SPEED, 20, 1),
            EffectCause::PLUGIN,
        ));

        $tick = $world->tick();
        self::assertInstanceOf(PlayerEffectChanged::class, $tick->events[0]);
        $player = $world->pluginPlayer('identity-one');
        self::assertNotNull($player);
        self::assertSame(19, $player->getEffects()->get(EffectType::SPEED)?->durationTicks);

        self::assertTrue($world->enqueuePluginEffectRemoval('identity-one', EffectType::SPEED, EffectCause::MILK));
        $world->tick();
        $updated = $world->pluginPlayer('identity-one');
        self::assertNotNull($updated);
        self::assertFalse($updated->getEffects()->has(EffectType::SPEED));
    }

    public function testInstantDamageChangesHealthWithoutPersistingAnActiveEffect(): void
    {
        $world = self::joinedWorld();
        self::assertTrue($world->enqueuePluginEffect(
            'identity-one',
            new EffectInstance(EffectType::INSTANT_DAMAGE, 1),
            EffectCause::POTION,
        ));

        $tick = $world->tick();

        self::assertInstanceOf(PlayerDamaged::class, $tick->events[0]);
        $player = $world->pluginPlayer('identity-one');
        self::assertNotNull($player);
        self::assertSame(14.0, $player->health);
        self::assertFalse($player->getEffects()->has(EffectType::INSTANT_DAMAGE));
    }

    public function testDeathClearsActiveEffects(): void
    {
        $world = self::joinedWorld();
        $world->enqueuePluginEffect('identity-one', new EffectInstance(EffectType::SPEED, 200), EffectCause::PLUGIN);
        $world->tick();

        self::assertTrue($world->enqueueKillPlayer('identity-one'));
        $world->tick();

        self::assertSame([], $world->pluginPlayer('identity-one')?->getEffects()->all());
    }

    public function testLavaContactHasIndependentDamageCadenceAndFireResistanceSuppressesIt(): void
    {
        [$simulation, $world, $generation] = self::environmentWorld();
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join('one', 'identity-one', 'One')));
        $simulation->tick();
        $lava = $generation->state('minecraft:lava');
        $world->setBlockState(0, 64, 0, $lava);
        $world->setBlockState(0, 65, 0, $lava);

        for ($tick = 2; $tick <= 10; ++$tick) {
            $simulation->tick();
        }
        $player = $simulation->pluginPlayer('identity-one');
        self::assertNotNull($player);
        self::assertSame(16.0, $player->health);

        self::assertTrue($simulation->enqueuePluginEffect(
            'identity-one',
            new EffectInstance(EffectType::FIRE_RESISTANCE, 200),
            EffectCause::PLUGIN,
        ));
        $simulation->tick();
        for ($tick = 12; $tick <= 20; ++$tick) {
            $simulation->tick();
        }
        $player = $simulation->pluginPlayer('identity-one');
        self::assertNotNull($player);
        self::assertSame(16.0, $player->health);
    }

    private static function joinedWorld(): WorldSimulation
    {
        $world = new WorldSimulation();
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();

        return $world;
    }

    /** @return array{WorldSimulation, World, GenerationBlockPalette} */
    private static function environmentWorld(): array
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

        return [
            new WorldSimulation(
                blockWorld: $world,
                blockPalette: $palette,
                waterState: $generation->state('minecraft:water'),
                lavaState: $generation->state('minecraft:lava'),
                blockStateRegistry: $states,
            ),
            $world,
            $generation,
        ];
    }
}
