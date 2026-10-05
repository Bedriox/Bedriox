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

namespace Bedriox\Server\Tests\Gameplay\Portal;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Portal\EndPortalSystem;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class EndPortalSystemTest extends TestCase
{
    public function testTwelveInwardFilledFramesActivateAndInvalidateNinePortalBlocks(): void
    {
        [$world, $states, $system] = self::portalWorld();
        self::placeRing($world, $states);
        $last = new BlockPosition(1, 64, -2);
        $filled = $system->filledFrameState($last);
        self::assertNotNull($filled);
        $world->setBlockState($last->x, $last->y, $last->z, $filled);

        $frame = $system->find($last);
        self::assertNotNull($frame);
        self::assertSame([0, 64, 0], [$frame->center->x, $frame->center->y, $frame->center->z]);
        self::assertCount(9, $system->activate($frame));
        foreach ($frame->interior() as $position) {
            self::assertSame(
                'minecraft:end_portal',
                $states->state($world->blockStateAt($position->x, $position->y, $position->z))->identifier(),
            );
        }

        $air = $states->internalId(CanonicalBlockState::from('minecraft:air'));
        $world->setBlockState(-2, 64, 0, $air);
        self::assertCount(9, $system->invalidateNear(new BlockPosition(-2, 64, 0)));
        foreach ($frame->interior() as $position) {
            self::assertSame($air->value, $world->blockStateAt($position->x, $position->y, $position->z)->value);
        }
    }

    public function testWrongFacingOrMissingEyeCannotActivate(): void
    {
        [$world, $states, $system] = self::portalWorld();
        self::placeRing($world, $states, wrongDirection: true);
        $position = new BlockPosition(0, 64, -2);

        self::assertNull($system->find($position));
        self::assertNull($system->filledFrameState($position));
    }

    /** @return array{World, BlockStateRegistry, EndPortalSystem} */
    private static function portalWorld(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('end-portal-test', 42),
            new FlatWorldGenerator($palette),
            new ChunkRepository(16),
        );

        return [$world, $states, new EndPortalSystem($world, $states)];
    }

    private static function placeRing(World $world, BlockStateRegistry $states, bool $wrongDirection = false): void
    {
        $frames = [
            [-1, -2, 'south'], [0, -2, 'south'], [1, -2, 'south'],
            [-1, 2, 'north'], [0, 2, 'north'], [1, 2, 'north'],
            [-2, -1, 'east'], [-2, 0, 'east'], [-2, 1, 'east'],
            [2, -1, 'west'], [2, 0, 'west'], [2, 1, 'west'],
        ];
        foreach ($frames as $index => [$x, $z, $direction]) {
            $eye = $index === 2 && !$wrongDirection ? 0 : 1;
            if ($wrongDirection && $index === 0) {
                $direction = 'north';
            }
            $state = $states->internalId(CanonicalBlockState::from('minecraft:end_portal_frame', [
                'end_portal_eye_bit' => $eye,
                'minecraft:cardinal_direction' => $direction,
            ]));
            $world->setBlockState($x, 64, $z, $state);
        }
    }
}
