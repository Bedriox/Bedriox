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

namespace Bedriox\Server\Tests\Gameplay\Nether;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Vanilla\Nether\HappyGhastEntity;
use Bedriox\Server\Gameplay\Nether\DriedGhastHydrationRuntime;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class DriedGhastHydrationRuntimeTest extends TestCase
{
    public function testFourHydrationStepsPersistStateThenSpawnOneBaby(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $world = new World(
            new WorldMetadata('world', 17),
            new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(0, 64, 0);
        $world->setBlockState(0, 64, 0, $states->internalId(CanonicalBlockState::from('minecraft:dried_ghast', [
            'minecraft:cardinal_direction' => 'west',
            'rehydration_level' => 0,
        ])));
        $spawned = [];
        $runtime = new DriedGhastHydrationRuntime($states, static function ($request) use (&$spawned): EntitySpawnOutcome {
            $entity = new HappyGhastEntity(
                EntityUuid::random(),
                count($spawned) + 1,
                $request->worldName,
                $request->position,
                yaw: $request->yaw,
            );
            $spawned[] = $entity;

            return EntitySpawnOutcome::success($entity);
        });

        foreach ([1, 2, 3] as $expected) {
            $result = $runtime->tick($world, $states, $position, true);
            self::assertTrue($result->blockChanged);
            self::assertTrue($result->scheduleNext);
            self::assertSame(DriedGhastHydrationRuntime::HYDRATION_STEP_TICKS, $result->nextDelayTicks);
            self::assertSame($expected, $states->state($world->blockStateAt(0, 64, 0))->properties()['rehydration_level']);
        }

        $completed = $runtime->tick($world, $states, $position, true);
        self::assertTrue($completed->ghastlingSpawned);
        self::assertFalse($completed->scheduleNext);
        self::assertCount(1, $spawned);
        self::assertTrue($spawned[0]->isBaby());
        self::assertSame(90.0, $spawned[0]->getYaw());
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(0, 64, 0))->identifier());
    }

    public function testInterruptedHydrationDriesOneStageAtATime(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $world = new World(
            new WorldMetadata('world', 17),
            new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(0, 64, 0);
        $world->setBlockState(0, 64, 0, $states->internalId(CanonicalBlockState::from('minecraft:dried_ghast', [
            'minecraft:cardinal_direction' => 'south',
            'rehydration_level' => 2,
        ])));
        $runtime = new DriedGhastHydrationRuntime($states, static fn(): EntitySpawnOutcome => EntitySpawnOutcome::failed('unused'));

        $first = $runtime->tick($world, $states, $position, false);
        self::assertTrue($first->scheduleNext);
        self::assertSame(1, $states->state($world->blockStateAt(0, 64, 0))->properties()['rehydration_level']);
        $second = $runtime->tick($world, $states, $position, false);
        self::assertFalse($second->scheduleNext);
        self::assertSame(0, $states->state($world->blockStateAt(0, 64, 0))->properties()['rehydration_level']);
    }
}
