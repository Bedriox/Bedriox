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

namespace Bedriox\Server\Tests\World\Collision;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Item\DroppedItemCollisionResolver;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Collision\PlayerCollisionResolver;
use Bedriox\Server\World\Environment\Fluid\FluidState;
use Bedriox\Server\World\Environment\Fluid\FluidType;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class GeneratedBlockCollisionTest extends TestCase
{
    public function testEveryGenerationStateHasAnExplicitCollisionDefinition(): void
    {
        [$states, $generation] = self::generationPalette();
        $collisions = BlockCollisionRegistry::forGenerationPalette($states, $generation);

        self::assertCount(204, $generation->states());
        self::assertGreaterThanOrEqual(count($generation->states()), $collisions->count());
        foreach ($generation->states() as $state) {
            self::assertTrue($collisions->contains($state));
        }
        foreach ($states->states() as $value => $state) {
            if (in_array($state->identifier(), ['minecraft:water', 'minecraft:lava'], true)) {
                $fluid = new InternalBlockStateId($value);
                self::assertTrue($collisions->contains($fluid));
                self::assertTrue($collisions->find($fluid)?->isEmpty());
            }
        }
    }

    public function testBucketSourceFluidVariantsArePassable(): void
    {
        [$states, $generation] = self::generationPalette();
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('bucket-fluid-collision', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $query = new BlockCollisionQuery(
            $world,
            $flat->air,
            [],
            BlockCollisionRegistry::forGenerationPalette($states, $generation),
        );
        $cell = new AxisAlignedBox(0.1, 64.1, 0.1, 0.9, 64.9, 0.9);

        foreach ([FluidType::WATER, FluidType::LAVA] as $type) {
            $source = $states->internalId(FluidState::source($type)->canonicalState());
            $world->setBlockState(0, 64, 0, $source);
            self::assertFalse($query->hasCollision($cell));
        }
    }

    public function testVegetationIsPassableAndPartialBlocksUseTheirStateShape(): void
    {
        [$states, $generation] = self::generationPalette();
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('generated-collision', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $query = new BlockCollisionQuery(
            $world,
            $flat->air,
            [],
            BlockCollisionRegistry::forGenerationPalette($states, $generation),
        );

        $world->setBlockState(0, 64, 0, $generation->state('minecraft:short_grass'));
        self::assertFalse($query->hasCollision(new AxisAlignedBox(0.1, 64.0, 0.1, 0.9, 64.9, 0.9)));

        $world->setBlockState(1, 64, 0, $generation->state('minecraft:snow_layer'));
        self::assertTrue($query->hasCollision(new AxisAlignedBox(1.1, 64.01, 0.1, 1.9, 64.1, 0.9)));
        self::assertFalse($query->hasCollision(new AxisAlignedBox(1.1, 64.13, 0.1, 1.9, 64.9, 0.9)));

        $world->setBlockState(2, 64, 0, $generation->state('minecraft:farmland'));
        self::assertTrue($query->hasCollision(new AxisAlignedBox(2.1, 64.8, 0.1, 2.9, 64.9, 0.9)));
        self::assertFalse($query->hasCollision(new AxisAlignedBox(2.1, 64.95, 0.1, 2.9, 65.0, 0.9)));

        $world->setBlockState(3, 64, 0, $generation->state('minecraft:glass_pane'));
        self::assertTrue($query->hasCollision(new AxisAlignedBox(3.48, 64.1, 0.48, 3.52, 64.9, 0.52)));
        self::assertFalse($query->hasCollision(new AxisAlignedBox(3.1, 64.1, 0.1, 3.3, 64.9, 0.3)));

        $world->setBlockState(4, 64, 0, $generation->state('minecraft:cactus'));
        self::assertTrue($query->hasCollision(new AxisAlignedBox(4.1, 64.1, 0.1, 4.9, 64.8, 0.9)));
        self::assertFalse($query->hasCollision(new AxisAlignedBox(4.0, 64.1, 0.0, 4.05, 64.8, 0.05)));

        $world->setBlockState(5, 64, 0, $generation->state('minecraft:lantern'));
        self::assertTrue($query->hasCollision(new AxisAlignedBox(5.4, 64.1, 0.4, 5.6, 64.4, 0.6)));
        self::assertFalse($query->hasCollision(new AxisAlignedBox(5.05, 64.6, 0.05, 5.2, 64.9, 0.2)));

        $world->setBlockState(6, 64, 0, $generation->state('minecraft:soul_sand'));
        self::assertTrue($query->hasCollision(new AxisAlignedBox(6.1, 64.8, 0.1, 6.9, 64.86, 0.9)));
        self::assertFalse($query->hasCollision(new AxisAlignedBox(6.1, 64.9, 0.1, 6.9, 65.0, 0.9)));
    }

    public function testDroppedItemsFallThroughVegetationOntoTheSupportingBlock(): void
    {
        [$states, $generation] = self::generationPalette();
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('generated-item-collision', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $world->setBlockState(0, 64, 0, $generation->state('minecraft:short_grass'));
        $resolver = new DroppedItemCollisionResolver(new BlockCollisionQuery(
            $world,
            $flat->air,
            [],
            BlockCollisionRegistry::forGenerationPalette($states, $generation),
        ));
        $items = new ItemEntityRegistry();
        $entity = $items->spawn(new InventoryStack('minecraft:cobblestone', 1, 1), new Position(0.5, 65.0, 0.5));

        for ($tick = 0; $tick < 40; ++$tick) {
            $entity = $resolver->resolve(
                $entity,
                $entity->tick(ItemEntityRegistry::GRAVITY, ItemEntityRegistry::DRAG),
            );
        }

        self::assertSame(64.0, $entity->position->y);
        self::assertSame(0.0, $entity->motion->y);
    }

    public function testPlayerMovementPassesThroughGeneratedVegetation(): void
    {
        [$states, $generation] = self::generationPalette();
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('generated-player-collision', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $world->setBlockState(1, 64, 0, $generation->state('minecraft:short_grass'));
        $resolver = new PlayerCollisionResolver(new BlockCollisionQuery(
            $world,
            $flat->air,
            [],
            BlockCollisionRegistry::forGenerationPalette($states, $generation),
        ));

        $result = $resolver->resolve(
            new Position(0.5, 64.0, 0.5),
            new Position(1.5, 64.0, 0.5),
            true,
        );

        self::assertSame(1.5, $result->position->x);
        self::assertFalse($result->collidedX);
        self::assertFalse($result->stepped);
    }

    /** @return array{BlockStateRegistry, GenerationBlockPalette} */
    private static function generationPalette(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());

        return [$states, GenerationBlockPalette::fromRegistry($states)];
    }
}
