<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Collision;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\DefaultBlockPalette;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class BlockCollisionQueryTest extends TestCase
{
    public function testQueryTracksCanonicalWorldMutation(): void
    {
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
        $world = new World(
            new WorldMetadata('collision-query', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $query = new BlockCollisionQuery($world, $palette->air);
        $cell = new AxisAlignedBox(0.1, 63.1, 0.1, 0.9, 63.9, 0.9);

        self::assertCount(1, $query->boxesIntersecting($cell));
        $world->setBlockState(0, 63, 0, $palette->air);
        self::assertFalse($query->hasCollision($cell));
        $world->setBlockState(0, 64, 0, $palette->grassBlock);
        self::assertTrue($query->hasCollision($cell->offset(0.0, 1.0, 0.0)));
    }

    public function testSourceWaterIsPassableButStoneRemainsSolid(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($registry);
        $default = DefaultBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('water-collision-query', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $query = new BlockCollisionQuery($world, $flat->air, [$default->water]);
        $cell = new AxisAlignedBox(0.1, 64.1, 0.1, 0.9, 64.9, 0.9);

        $world->setBlockState(0, 64, 0, $default->water);
        self::assertFalse($query->hasCollision($cell));
        $world->setBlockState(0, 64, 0, $default->stone);
        self::assertTrue($query->hasCollision($cell));
    }
}
