<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Block;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BlockPlacementStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlockPlacementStateResolverTest extends TestCase
{
    public function testEveryBlockFaceSelectsTheMatchingPillarAxis(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($registry);
        $resolver = new BlockPlacementStateResolver($registry);
        $base = $registry->state($palette->state('minecraft:oak_log'));

        foreach ([0 => 'y', 1 => 'y', 2 => 'z', 3 => 'z', 4 => 'x', 5 => 'x'] as $face => $axis) {
            $resolved = $registry->state($resolver->resolve($base, $face));
            self::assertSame('minecraft:oak_log', $resolved->identifier());
            self::assertSame($axis, $resolved->properties()['pillar_axis'] ?? null);
        }
    }

    public function testNonPillarBlockStateIsPreservedExactly(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new BlockPlacementStateResolver($registry);
        $state = CanonicalBlockState::from('minecraft:grass_block');

        self::assertSame($registry->internalId($state)->value, $resolver->resolve($state, 5)->value);
    }

    public function testMissingPillarVariantFailsDeterministically(): void
    {
        $vertical = CanonicalBlockState::from('minecraft:test_pillar', ['pillar_axis' => 'y']);
        $resolver = new BlockPlacementStateResolver(new BlockStateRegistry([$vertical]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Canonical block state is not registered internally.');
        $resolver->resolve($vertical, 5);
    }
}
