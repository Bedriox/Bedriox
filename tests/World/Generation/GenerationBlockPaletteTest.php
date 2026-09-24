<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Generation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GenerationBlockPaletteTest extends TestCase
{
    public function testPinnedRegistryProvidesEveryRequiredGenerationState(): void
    {
        $data = BedrockDataSet::bundled();
        $palette = GenerationBlockPalette::fromRegistry(new BlockStateRegistry($data->blockStateRegistry()->states()));

        self::assertLessThanOrEqual(256, count($palette->states()));
        self::assertSame($palette->state('minecraft:air'), $palette->states()[$palette->index($palette->state('minecraft:air'))]);
        self::assertNotSame($palette->state('minecraft:oak_log')->value, $palette->state('minecraft:acacia_log')->value);
    }

    public function testPillarStatesDefaultToVerticalAndExposeEveryExactAxisVariant(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($registry);
        $identifiers = [
            'minecraft:deepslate',
            'minecraft:oak_log',
            'minecraft:birch_log',
            'minecraft:spruce_log',
            'minecraft:acacia_log',
            'minecraft:dark_oak_log',
            'minecraft:jungle_log',
            'minecraft:cherry_log',
            'minecraft:mangrove_log',
            'minecraft:pale_oak_log',
            'minecraft:hay_block',
            'minecraft:basalt',
        ];

        foreach ($identifiers as $identifier) {
            self::assertSame('y', $registry->state($palette->state($identifier))->properties()['pillar_axis'] ?? null);
            foreach (BlockAxis::cases() as $axis) {
                $state = $palette->state($identifier, $axis);
                self::assertSame($axis->value, $registry->state($state)->properties()['pillar_axis'] ?? null);
                self::assertSame($state, $palette->states()[$palette->index($state)]);
            }
        }
    }

    public function testAxisSelectionRejectsBlocksWithoutPillarState(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($registry);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not define pillar_axis');
        $palette->state('minecraft:stone', BlockAxis::X);
    }

    public function testPaletteRejectsARegistryMissingAnExactPillarVariant(): void
    {
        $states = array_values(array_filter(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
            static fn($state): bool => $state->identifier() !== 'minecraft:oak_log'
                || ($state->properties()['pillar_axis'] ?? null) !== 'x',
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('required x-axis generation state minecraft:oak_log');
        GenerationBlockPalette::fromRegistry(new BlockStateRegistry($states));
    }

    public function testMutableBuilderCompactsOnlyStatesUsedByEachSection(): void
    {
        $data = BedrockDataSet::bundled();
        $palette = GenerationBlockPalette::fromRegistry(new BlockStateRegistry($data->blockStateRegistry()->states()));
        $builder = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);
        $builder->set(0, 64, 0, $palette->state('minecraft:grass_block'));
        $builder->setWorld(15, 65, 15, $palette->state('minecraft:oak_log'));
        $builder->setWorld(16, 65, 15, $palette->state('minecraft:stone'));

        $sections = $builder->sections();
        self::assertCount(1, $sections);
        self::assertSame($palette->state('minecraft:grass_block')->value, $sections[0]->blockStateAt(0, 0, 0)->value);
        self::assertSame($palette->state('minecraft:oak_log')->value, $sections[0]->blockStateAt(15, 1, 15)->value);
        self::assertLessThanOrEqual(3, count($sections[0]->palette()));
    }
}
