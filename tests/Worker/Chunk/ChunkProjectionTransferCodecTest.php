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

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ChunkSerializer;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\Worker\Chunk\ChunkProjectionTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferException;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\FlatWorldGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChunkProjectionTransferCodecTest extends TestCase
{
    public function testPackedProjectionMatchesTheAuthoritativeSerializerAndShrinksWorkerInput(): void
    {
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $states = new BlockStateRegistry($networkStates->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $chunk = (new FlatWorldGenerator($palette))->generate(new ChunkPosition(-9, 12))
            ->withBlockState(3, 64, 7, $palette->dirt);
        $blocks = new BlockNetworkTranslator($states, $networkStates);
        $codec = new ChunkProjectionTransferCodec();

        $packed = $codec->encode($chunk, $states);
        $column = $codec->decodeColumn($packed, $states, $blocks);

        self::assertEquals(
            (new BedrockChunkPacketSerializer($blocks))->serialize($chunk),
            ChunkSerializer::fullColumn($column),
        );
        self::assertLessThan(strlen((new ChunkTransferCodec())->encode($chunk, $states)) / 4, strlen($packed));
    }

    public function testCorruptTruncatedAndTrailingProjectionsFailClosed(): void
    {
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $states = new BlockStateRegistry($networkStates->states());
        $chunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)))
            ->generate(new ChunkPosition(0, 0));
        $blocks = new BlockNetworkTranslator($states, $networkStates);
        $codec = new ChunkProjectionTransferCodec();
        $encoded = $codec->encode($chunk, $states);
        $corrupt = $encoded;
        $corrupt[20] = chr(ord($corrupt[20]) ^ 1);

        foreach ([$corrupt, substr($encoded, 0, -1), $encoded . "\0"] as $invalid) {
            try {
                $codec->decodeColumn($invalid, $states, $blocks);
                self::fail('A malformed chunk projection was accepted.');
            } catch (ChunkTransferException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMixedBiomeGeneratorChunkSurvivesWorkerProjection(): void
    {
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $states = new BlockStateRegistry($networkStates->states());
        $chunk = (new DefaultWorldGenerator(0, $states))->generate(new ChunkPosition(2, -14));
        $blocks = new BlockNetworkTranslator($states, $networkStates);
        $codec = new ChunkProjectionTransferCodec();

        $column = $codec->decodeColumn(
            $codec->encode($chunk, $states),
            $states,
            $blocks,
        );

        self::assertNotSame('', ChunkSerializer::fullColumn($column)->data);
    }

    public function testPreparedProjectionPreservesClientBlockEntitiesWithoutContainerContents(): void
    {
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $states = new BlockStateRegistry($networkStates->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $chest = ContainerBlockEntity::empty(BlockEntityType::Chest, new BlockPosition(1, 64, 1))
            ->withCustomName('Prepared')
            ->withPair(new BlockPosition(2, 64, 1), false);
        $chest = $chest->withInventory($chest->inventory->withStack(
            7,
            new ContainerItemStack('minecraft:emerald', 3),
        ));
        $chunk = (new FlatWorldGenerator($palette))->generate(new ChunkPosition(0, 0))
            ->withBlockEntity($chest);
        $blocks = new BlockNetworkTranslator($states, $networkStates);
        $codec = new ChunkProjectionTransferCodec();

        $column = $codec->decodeColumn($codec->encode($chunk, $states), $states, $blocks);

        self::assertCount(1, $column->blockEntityNbt);
        self::assertStringContainsString('Prepared', $column->blockEntityNbt[0]);
        self::assertStringContainsString('pairx', $column->blockEntityNbt[0]);
        self::assertStringNotContainsString('Items', $column->blockEntityNbt[0]);
        self::assertEquals(
            (new BedrockChunkPacketSerializer($blocks))->serialize($chunk),
            ChunkSerializer::fullColumn($column),
        );
    }

    #[DataProvider('dimensionBounds')]
    public function testProjectionCarriesDimensionSpecificHeightAndBiomeBounds(
        WorldDimension $dimension,
        int $wireDimension,
        int $minimumSectionY,
        int $maximumSectionY,
    ): void {
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $states = new BlockStateRegistry($networkStates->states());
        $chunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)))
            ->generate(new ChunkPosition(7, -8));
        $codec = new ChunkProjectionTransferCodec();

        $column = $codec->decodeColumn(
            $codec->encode($chunk, $states, $dimension),
            $states,
            new BlockNetworkTranslator($states, $networkStates),
        );

        self::assertSame($wireDimension, $column->dimension);
        self::assertSame($minimumSectionY, $column->minSectionY);
        self::assertSame($maximumSectionY, $column->maxSectionY);
        self::assertCount($maximumSectionY - $minimumSectionY + 1, $column->biomes);
        foreach ($column->sections as $index => $section) {
            self::assertSame($minimumSectionY + $index, $section->sectionY);
        }
    }

    /** @return iterable<string, array{WorldDimension, int, int, int}> */
    public static function dimensionBounds(): iterable
    {
        yield 'overworld' => [WorldDimension::OVERWORLD, 0, -4, 19];
        yield 'nether' => [WorldDimension::NETHER, 1, 0, 7];
        yield 'end' => [WorldDimension::END, 2, 0, 15];
    }
}
