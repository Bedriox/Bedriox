<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\BiomeRuntimeIdMap;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\DefaultBlockPalette;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultWorldGeneratorTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        yield 'zero' => [0];
        yield 'positive' => [1];
        yield 'negative one remains deterministic' => [-1];
        yield 'minimum' => [-2_147_483_648];
        yield 'maximum' => [2_147_483_647];
    }

    #[DataProvider('seeds')]
    public function testGenerationIsDeterministicBoundedAndSpawnIsDry(int $seed): void
    {
        [$generator, $palette] = self::generator($seed);
        $position = new ChunkPosition(-2, 3);
        $first = $generator->generate($position);
        $second = $generator->generate($position);

        self::assertSame(self::signature($first), self::signature($second));
        self::assertLessThanOrEqual(12, count($first->populatedSections()));
        foreach ($first->populatedSections() as $section) {
            self::assertLessThanOrEqual(13, count($section->palette()));
        }

        $spawn = $generator->defaultSpawn();
        $spawnChunk = $generator->generate(new ChunkPosition((int) floor($spawn->x / 16), (int) floor($spawn->z / 16)));
        $localX = (($spawn->x % 16) + 16) % 16;
        $localZ = (($spawn->z % 16) + 16) % 16;
        self::assertNotSame($palette->air->value, $spawnChunk->blockStateAt($localX, $spawn->y - 1, $localZ)->value);
        self::assertSame($palette->air->value, $spawnChunk->blockStateAt($localX, $spawn->y, $localZ)->value);
        self::assertSame($palette->air->value, $spawnChunk->blockStateAt($localX, $spawn->y + 1, $localZ)->value);
    }

    public function testNegativeChunkSeamUsesWorldCoordinatesAndBiomeIdsAreAdmitted(): void
    {
        [$generator] = self::generator(9_123);
        $west = $generator->generate(new ChunkPosition(-1, 0));
        $east = $generator->generate(new ChunkPosition(0, 0));
        for ($z = 0; $z < 16; ++$z) {
            $westHeight = self::highestTerrain($west, 15, $z);
            $eastHeight = self::highestTerrain($east, 0, $z);
            self::assertLessThanOrEqual(12, abs($westHeight - $eastHeight));
            BiomeRuntimeIdMap::id($west->biomeAt(15, $westHeight, $z));
            BiomeRuntimeIdMap::id($east->biomeAt(0, $eastHeight, $z));
        }
    }

    /** @return array{DefaultWorldGenerator, DefaultBlockPalette} */
    private static function generator(int $seed): array
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = DefaultBlockPalette::fromRegistry($registry);

        return [new DefaultWorldGenerator($seed, $palette), $palette];
    }

    private static function signature(Chunk $chunk): string
    {
        $values = [];
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                for ($y = Chunk::MIN_Y; $y <= 120; ++$y) {
                    $values[] = $chunk->blockStateAt($x, $y, $z)->value;
                }
                $values[] = $chunk->biomeAt($x, 64, $z)->identifier;
            }
        }

        return hash('sha256', serialize($values));
    }

    private static function highestTerrain(Chunk $chunk, int $x, int $z): int
    {
        for ($y = 120; $y >= Chunk::MIN_Y; --$y) {
            if ($chunk->blockStateAt($x, $y, $z)->value !== $chunk->airState()->value) {
                return $y;
            }
        }

        self::fail('Generated column contained no terrain.');
    }
}
