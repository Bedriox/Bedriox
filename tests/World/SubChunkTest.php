<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\SubChunk;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SubChunkTest extends TestCase
{
    public function testLayeredSectionUsesInternalPaletteAndExpectedIndexOrder(): void
    {
        $air = new InternalBlockStateId(10);
        $solid = new InternalBlockStateId(20);
        $layers = array_fill(0, 16, $air);
        $layers[15] = $solid;
        $section = SubChunk::layered(3, $layers);

        self::assertSame([$air, $solid], $section->palette());
        self::assertSame(10, $section->blockStateAt(15, 14, 15)->value);
        self::assertSame(20, $section->blockStateAt(0, 15, 0)->value);
        self::assertSame(1, $section->paletteIndexAt(15, 15, 15));
    }

    public function testUniformSectionReturnsItsOnlyStateAtEveryCorner(): void
    {
        $state = new InternalBlockStateId(7);
        $section = SubChunk::uniform(-4, $state);

        self::assertSame(7, $section->blockStateAt(0, 0, 0)->value);
        self::assertSame(7, $section->blockStateAt(15, 15, 15)->value);
        self::assertSame([7], array_map(static fn(InternalBlockStateId $id): int => $id->value, $section->palette()));
    }

    public function testOneCellReplacementReturnsANewBoundedSnapshot(): void
    {
        $air = new InternalBlockStateId(1);
        $grass = new InternalBlockStateId(2);
        $section = SubChunk::uniform(3, $grass);
        $changed = $section->withBlockState(15, 15, 15, $air);

        self::assertNotSame($section, $changed);
        self::assertSame(2, $section->blockStateAt(15, 15, 15)->value);
        self::assertSame(1, $changed->blockStateAt(15, 15, 15)->value);
        self::assertSame(2, $changed->blockStateAt(14, 15, 15)->value);
        self::assertSame($changed, $changed->withBlockState(15, 15, 15, $air));
    }

    public function testReplacementPaletteUsesTheFullByteRangeAndRejectsA257thState(): void
    {
        $section = SubChunk::uniform(0, new InternalBlockStateId(0));
        for ($index = 1; $index <= 255; ++$index) {
            $section = $section->withBlockState(
                $index & 0x0f,
                0,
                ($index >> 4) & 0x0f,
                new InternalBlockStateId($index),
            );
        }
        self::assertCount(256, $section->palette());
        self::assertSame(255, $section->paletteIndexAt(15, 0, 15));

        $this->expectException(InvalidArgumentException::class);
        $section->withBlockState(0, 0, 0, new InternalBlockStateId(256));
    }

    public function testInvalidLayerCountsCoordinatesAndSectionHeightsAreRejected(): void
    {
        $state = new InternalBlockStateId(0);
        $invalidFactories = [
            static fn(): SubChunk => SubChunk::layered(0, array_fill(0, 15, $state)),
            static fn(): SubChunk => SubChunk::uniform(-5, $state),
        ];
        foreach ($invalidFactories as $factory) {
            try {
                $factory();
                self::fail('Invalid subchunk input was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $section = SubChunk::uniform(0, $state);
        foreach ([[-1, 0, 0], [0, 16, 0], [0, 0, 16]] as [$x, $y, $z]) {
            try {
                $section->blockStateAt($x, $y, $z);
                self::fail('Invalid local coordinate was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
