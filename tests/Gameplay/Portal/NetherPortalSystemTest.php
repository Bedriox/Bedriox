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
use Bedriox\Server\Gameplay\Portal\NetherPortalSystem;
use Bedriox\Server\Gameplay\Portal\PortalAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class NetherPortalSystemTest extends TestCase
{
    public function testValidFrameIgnitesWithAxisStateAndInvalidatesWhenFrameBreaks(): void
    {
        [$world, $states, $system] = self::portalWorld();
        self::placeFrame($world, $states, PortalAxis::X);

        $mutations = $system->ignite(new BlockPosition(0, 64, 0));

        self::assertCount(6, $mutations);
        foreach ($mutations as $mutation) {
            $state = $states->state($world->blockStateAt(
                $mutation->position->x,
                $mutation->position->y,
                $mutation->position->z,
            ));
            self::assertSame('minecraft:portal', $state->identifier());
            self::assertSame('x', $state->properties()['portal_axis']);
        }

        $air = $states->internalId(CanonicalBlockState::from('minecraft:air'));
        $broken = new BlockPosition(-1, 65, 0);
        $world->setBlockState($broken->x, $broken->y, $broken->z, $air);
        $removed = $system->invalidateNear($broken);

        self::assertCount(6, $removed);
        foreach ($removed as $mutation) {
            self::assertSame($air->value, $world->blockStateAt(
                $mutation->position->x,
                $mutation->position->y,
                $mutation->position->z,
            )->value);
        }
    }

    public function testIncompleteOrUndersizedFrameDoesNotIgnite(): void
    {
        [$world, $states, $system] = self::portalWorld();
        self::placeFrame($world, $states, PortalAxis::Z);
        $air = $states->internalId(CanonicalBlockState::from('minecraft:air'));
        $world->setBlockState(0, 67, 1, $air);

        self::assertSame([], $system->ignite(new BlockPosition(0, 64, 0)));
    }

    public function testRemovingOnePortalCellCollapsesTheConnectedPortalEvenWhenFrameRemains(): void
    {
        [$world, $states, $system] = self::portalWorld();
        self::placeFrame($world, $states, PortalAxis::X);
        self::assertCount(6, $system->ignite(new BlockPosition(0, 64, 0)));
        $air = $states->internalId(CanonicalBlockState::from('minecraft:air'));
        $removed = new BlockPosition(0, 65, 0);
        $world->setBlockState($removed->x, $removed->y, $removed->z, $air);

        self::assertCount(5, $system->invalidateNear($removed, true));
        foreach ([0, 1] as $x) {
            foreach ([64, 65, 66] as $y) {
                self::assertSame($air->value, $world->blockStateAt($x, $y, 0)->value);
            }
        }
    }

    public function testStandardBuildProducesFrameAndFilledInterior(): void
    {
        [$world, $states, $system] = self::portalWorld();
        $origin = new BlockPosition(10, 64, 10);

        self::assertCount(20, $system->build($origin, PortalAxis::Z));
        $frame = $system->detect($origin);
        self::assertNotNull($frame);
        self::assertSame(PortalAxis::Z, $frame->axis);
        self::assertSame(2, $frame->width);
        self::assertSame(3, $frame->height);
        self::assertSame('z', $states->state($world->blockStateAt(10, 65, 11))->properties()['portal_axis']);
    }

    public function testFallbackSiteCarvesArrivalSpaceAndCreatesSolidFloor(): void
    {
        [$world, $states, $system] = self::portalWorld();
        $origin = new BlockPosition(0, 2, 0);

        self::assertNotEmpty($system->prepareFallbackBuildSite($origin, PortalAxis::X));
        $system->build($origin, PortalAxis::X);

        $frame = $system->detect($origin);
        self::assertNotNull($frame);
        self::assertTrue($system->hasGeneratedLandingPlatform($frame));
        for ($depth = -4; $depth <= 4; ++$depth) {
            self::assertSame(
                'minecraft:obsidian',
                $states->state($world->blockStateAt(0, 1, $depth))->identifier(),
            );
        }
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(0, 2, 4))->identifier());
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(0, 6, -4))->identifier());
    }

    public function testPortalStatesArePassableSoPlayersCanEnterThePortalPlane(): void
    {
        [, $states] = self::portalWorld();
        $collisions = BlockCollisionRegistry::forGenerationPalette(
            $states,
            GenerationBlockPalette::fromRegistry($states),
        );
        foreach (['x', 'z'] as $axis) {
            $portal = $states->internalId(CanonicalBlockState::from('minecraft:portal', ['portal_axis' => $axis]));
            self::assertTrue($collisions->contains($portal));
            self::assertTrue($collisions->find($portal)?->isEmpty());
        }
    }

    /** @return array{World, BlockStateRegistry, NetherPortalSystem} */
    private static function portalWorld(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('portal-test', 42),
            new FlatWorldGenerator($palette),
            new ChunkRepository(16),
        );

        return [$world, $states, new NetherPortalSystem($world, $states)];
    }

    private static function placeFrame(World $world, BlockStateRegistry $states, PortalAxis $axis): void
    {
        $obsidian = $states->internalId(CanonicalBlockState::from('minecraft:obsidian'));
        $dx = $axis->stepX();
        $dz = $axis->stepZ();
        for ($horizontal = -1; $horizontal <= 2; ++$horizontal) {
            for ($vertical = -1; $vertical <= 3; ++$vertical) {
                if ($horizontal !== -1 && $horizontal !== 2 && $vertical !== -1 && $vertical !== 3) {
                    continue;
                }
                $world->setBlockState(
                    $dx * $horizontal,
                    64 + $vertical,
                    $dz * $horizontal,
                    $obsidian,
                );
            }
        }
    }
}
