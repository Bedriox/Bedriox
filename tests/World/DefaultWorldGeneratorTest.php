<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\BiomeRuntimeIdMap;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultWorldGeneratorTest extends TestCase
{
    public function testDefaultGeneratorIsOfficialVersionOne(): void
    {
        [$generator] = self::generator(0);

        self::assertSame('default', $generator->name());
        self::assertSame(1, $generator->version());
    }

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
        [$generator, $states] = self::generator($seed);
        $position = new ChunkPosition(-2, 3);
        $first = $generator->generate($position);
        $second = $generator->generate($position);

        self::assertSame(self::signature($first), self::signature($second));
        self::assertLessThanOrEqual(12, count($first->populatedSections()));
        foreach ($first->populatedSections() as $section) {
            self::assertLessThanOrEqual(32, count($section->palette()));
        }

        $spawn = $generator->defaultSpawn();
        $spawnChunk = $generator->generate(new ChunkPosition((int) floor($spawn->x / 16), (int) floor($spawn->z / 16)));
        $localX = (($spawn->x % 16) + 16) % 16;
        $localZ = (($spawn->z % 16) + 16) % 16;
        $air = $states->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::air());
        self::assertNotSame($air->value, $spawnChunk->blockStateAt($localX, $spawn->y - 1, $localZ)->value);
        self::assertSame($air->value, $spawnChunk->blockStateAt($localX, $spawn->y, $localZ)->value);
        self::assertSame($air->value, $spawnChunk->blockStateAt($localX, $spawn->y + 1, $localZ)->value);
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
            BiomeRuntimeIdMap::bundled()->id($west->biomeAt(15, $westHeight, $z));
            BiomeRuntimeIdMap::bundled()->id($east->biomeAt(0, $eastHeight, $z));
        }
    }

    public function testChunkOutputDoesNotDependOnGenerationOrder(): void
    {
        [$westFirst] = self::generator(77_031);
        [$eastFirst] = self::generator(77_031);
        $westPosition = new ChunkPosition(-1, 2);
        $eastPosition = new ChunkPosition(0, 2);

        $westA = $westFirst->generate($westPosition);
        $eastA = $westFirst->generate($eastPosition);
        $eastB = $eastFirst->generate($eastPosition);
        $westB = $eastFirst->generate($westPosition);

        self::assertSame(self::signature($westA), self::signature($westB));
        self::assertSame(self::signature($eastA), self::signature($eastB));
    }

    public function testOpenTerrainRemainsContinuouslyFilledAcrossOceanChunkSeams(): void
    {
        [$generator, $states] = self::generator(0);
        $chunks = [
            $generator->generate(new ChunkPosition(43, -16)),
            $generator->generate(new ChunkPosition(44, -16)),
        ];
        $air = $states->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::air())->value;
        $fluidIdentifiers = ['minecraft:water', 'minecraft:ice', 'minecraft:frosted_ice'];
        $checked = 0;

        foreach ($chunks as $chunk) {
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    if (!in_array(self::identifierAt($chunk, $states, $x, DefaultWorldGenerator::SEA_LEVEL, $z), $fluidIdentifiers, true)) {
                        continue;
                    }
                    ++$checked;
                    for ($y = DefaultWorldGenerator::SEA_LEVEL - 1; $y > Chunk::MIN_Y; --$y) {
                        $identifier = self::identifierAt($chunk, $states, $x, $y, $z);
                        if (in_array($identifier, $fluidIdentifiers, true)) {
                            continue;
                        }
                        self::assertNotSame(
                            $air,
                            $chunk->blockStateAt($x, $y, $z)->value,
                            'Open water must not contain an air gap before reaching its terrain floor.',
                        );
                        break;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'The selected adjacent chunks must exercise an ocean seam.');
    }

    public function testDeterministicStructureFamiliesAppearInTheirSelectedRegions(): void
    {
        [$generator, $states] = self::generator(0);

        $village = self::paletteIdentifiers($generator->generate(new ChunkPosition(-58, -11)), $states);
        self::assertArrayHasKey('minecraft:grass_path', $village);
        self::assertArrayHasKey('minecraft:oak_planks', $village);

        $oceanRuin = self::paletteIdentifiers($generator->generate(new ChunkPosition(43, -16)), $states);
        self::assertArrayHasKey('minecraft:prismarine', $oceanRuin);
        self::assertArrayHasKey('minecraft:sea_lantern', $oceanRuin);

        $stronghold = self::paletteIdentifiers($generator->generate(new ChunkPosition(16, 14)), $states);
        self::assertArrayHasKey('minecraft:stone_bricks', $stronghold);
        self::assertTrue(
            isset($stronghold['minecraft:mossy_stone_bricks'])
            || isset($stronghold['minecraft:cracked_stone_bricks']),
        );
    }

    public function testVillagePathsAndFoundationsNeverUseTreesAsGround(): void
    {
        [$generator, $states] = self::generator(0);
        $chunk = $generator->generate(new ChunkPosition(-58, -11));
        $treeStates = [
            'minecraft:oak_log', 'minecraft:oak_leaves', 'minecraft:birch_log', 'minecraft:birch_leaves',
            'minecraft:spruce_log', 'minecraft:spruce_leaves', 'minecraft:acacia_log', 'minecraft:acacia_leaves',
            'minecraft:dark_oak_log', 'minecraft:dark_oak_leaves', 'minecraft:jungle_log', 'minecraft:jungle_leaves',
            'minecraft:cherry_log', 'minecraft:cherry_leaves', 'minecraft:mangrove_log', 'minecraft:mangrove_leaves',
            'minecraft:pale_oak_log', 'minecraft:pale_oak_leaves',
        ];
        $checked = 0;
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                for ($y = Chunk::MIN_Y + 1; $y <= 180; ++$y) {
                    $identifier = self::identifierAt($chunk, $states, $x, $y, $z);
                    if ($identifier !== 'minecraft:cobblestone' && $identifier !== 'minecraft:grass_path') {
                        continue;
                    }
                    ++$checked;
                    self::assertNotContains(
                        self::identifierAt($chunk, $states, $x, $y - 1, $z),
                        $treeStates,
                        'Village paths and cobblestone foundations must be anchored to terrain, not foliage.',
                    );
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testVegetationUsesNaturalSubstrateInsteadOfStructuresOrFoliage(): void
    {
        [$generator, $states] = self::generator(0);
        $chunk = $generator->generate(new ChunkPosition(-58, -11));
        $plants = [
            'minecraft:short_grass', 'minecraft:tall_grass', 'minecraft:dandelion', 'minecraft:poppy',
            'minecraft:blue_orchid', 'minecraft:allium', 'minecraft:azure_bluet', 'minecraft:red_tulip',
            'minecraft:white_tulip', 'minecraft:pink_tulip', 'minecraft:oxeye_daisy',
            'minecraft:lily_of_the_valley', 'minecraft:deadbush', 'minecraft:red_mushroom',
            'minecraft:brown_mushroom', 'minecraft:bamboo', 'minecraft:cactus',
        ];
        $naturalSubstrates = [
            'minecraft:dirt', 'minecraft:grass_block', 'minecraft:coarse_dirt', 'minecraft:podzol',
            'minecraft:mud', 'minecraft:moss_block', 'minecraft:pale_moss_block', 'minecraft:mycelium',
            'minecraft:dirt_with_roots', 'minecraft:sand', 'minecraft:red_sand',
            'minecraft:white_terracotta', 'minecraft:orange_terracotta', 'minecraft:yellow_terracotta',
            'minecraft:brown_terracotta', 'minecraft:red_terracotta',
        ];
        $checked = 0;
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                for ($y = Chunk::MIN_Y + 1; $y <= 180; ++$y) {
                    $identifier = self::identifierAt($chunk, $states, $x, $y, $z);
                    if (!in_array($identifier, $plants, true)) {
                        continue;
                    }
                    $below = self::identifierAt($chunk, $states, $x, $y - 1, $z);
                    if (($identifier === 'minecraft:bamboo' || $identifier === 'minecraft:cactus')
                        && $below === $identifier) {
                        continue;
                    }
                    ++$checked;
                    self::assertContains(
                        $below,
                        $naturalSubstrates,
                        "Vegetation $identifier must be rooted in an admitted natural substrate.",
                    );
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testVillagePopulationPassesRemainDeterministic(): void
    {
        [$first] = self::generator(0);
        [$second] = self::generator(0);
        $position = new ChunkPosition(-58, -11);

        self::assertSame(self::signature($first->generate($position)), self::signature($second->generate($position)));
    }

    /** @return array{DefaultWorldGenerator, BlockStateRegistry} */
    private static function generator(int $seed): array
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        return [new DefaultWorldGenerator($seed, $registry), $registry];
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

    /** @return array<string, true> */
    private static function paletteIdentifiers(Chunk $chunk, BlockStateRegistry $states): array
    {
        $identifiers = [];
        foreach ($chunk->populatedSections() as $section) {
            foreach ($section->palette() as $state) {
                $identifiers[$states->state($state)->identifier()] = true;
            }
        }

        return $identifiers;
    }

    private static function identifierAt(
        Chunk $chunk,
        BlockStateRegistry $states,
        int $x,
        int $y,
        int $z,
    ): string {
        return $states->state($chunk->blockStateAt($x, $y, $z))->identifier();
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
