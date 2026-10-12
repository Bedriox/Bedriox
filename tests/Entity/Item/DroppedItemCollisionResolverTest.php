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

namespace Bedriox\Server\Tests\Entity\Item;

use Bedriox\Server\Entity\Item\DroppedItemCollisionResolver;
use Bedriox\Server\Entity\Item\ItemEntityMotion;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\CollisionBoxQuery;
use Bedriox\Server\World\Environment\Fluid\FluidCell;
use Bedriox\Server\World\Environment\Fluid\FluidCurrentResolver;
use Bedriox\Server\World\Environment\Fluid\FluidState;
use Bedriox\Server\World\Environment\Fluid\FluidType;
use Bedriox\Server\World\Environment\Fluid\FluidWorldView;
use PHPUnit\Framework\TestCase;

final class DroppedItemCollisionResolverTest extends TestCase
{
    public function testDroppedItemSettlesAtTheSurfaceWithoutJumpingAboveIt(): void
    {
        $registry = new ItemEntityRegistry();
        $entity = $registry->spawn(
            new InventoryStack('minecraft:cobblestone', 1, 1),
            new Position(0.5, 64.2, 0.5),
        );
        $resolver = new DroppedItemCollisionResolver(self::ground());

        for ($tick = 0; $tick < 40; ++$tick) {
            $advanced = $entity->tick(ItemEntityRegistry::GRAVITY, ItemEntityRegistry::DRAG);
            $entity = $resolver->resolve($entity, $advanced);
            self::assertGreaterThanOrEqual(64.0, $entity->position->y);
            self::assertLessThanOrEqual(64.2, $entity->position->y);
        }
        self::assertSame(64.0, $entity->position->y);
        self::assertSame(0.0, $entity->motion->y);
    }

    public function testItemDoesNotPassThroughTheSideOfABlock(): void
    {
        $registry = new ItemEntityRegistry();
        $entity = $registry->spawn(
            new InventoryStack('minecraft:cobblestone', 1, 1),
            new Position(0.5, 64.0, 0.5),
            new ItemEntityMotion(0.5, 0.0, 0.0),
        );
        $obstacle = AxisAlignedBox::unitAt(1, 64, 0);
        $query = new class ($obstacle) implements CollisionBoxQuery {
            public function __construct(private AxisAlignedBox $obstacle) {}

            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                return $this->obstacle->intersects($area) ? [$this->obstacle] : [];
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                return $this->obstacle->intersects($area);
            }
        };

        $resolved = (new DroppedItemCollisionResolver($query))->resolve(
            $entity,
            $entity->tick(ItemEntityRegistry::GRAVITY, ItemEntityRegistry::DRAG),
        );
        self::assertSame(0.875, $resolved->position->x);
        self::assertSame(0.0, $resolved->motion->x);
    }

    public function testItemEmbeddedByAPlacedBlockSurfacesOnTheNextPhysicsStep(): void
    {
        $registry = new ItemEntityRegistry();
        $entity = $registry->spawn(
            new InventoryStack('minecraft:cobblestone', 1, 1),
            new Position(0.5, 64.2, 0.5),
        );
        $block = AxisAlignedBox::unitAt(0, 64, 0);
        $query = new class ($block) implements CollisionBoxQuery {
            public function __construct(private AxisAlignedBox $block) {}

            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                return $this->block->intersects($area) ? [$this->block] : [];
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                return $this->block->intersects($area);
            }
        };

        $resolved = (new DroppedItemCollisionResolver($query))->resolve(
            $entity,
            $entity->tick(ItemEntityRegistry::GRAVITY, ItemEntityRegistry::DRAG),
        );

        self::assertSame(65.0, $resolved->position->y);
        self::assertSame(0.0, $resolved->motion->y);
    }

    public function testDroppedItemIsCarriedByWaterDepthGradient(): void
    {
        $registry = new ItemEntityRegistry();
        $entity = $registry->spawn(
            new InventoryStack('minecraft:cobblestone', 1, 1),
            new Position(0.5, 64.0, 0.5),
        );
        $fluids = new class implements FluidWorldView {
            public function cellAt(BlockPosition $position): ?FluidCell
            {
                if ($position->y < -64 || $position->y > 319) {
                    return null;
                }
                $depth = match ([$position->x, $position->y, $position->z]) {
                    [0, 64, 0] => 1,
                    [-1, 64, 0] => 0,
                    [1, 64, 0] => 2,
                    default => null,
                };

                return $depth === null
                    ? FluidCell::air()
                    : new FluidCell(
                        (new FluidState(FluidType::WATER, $depth, false))->canonicalState(),
                        true,
                        false,
                    );
            }
        };
        $emptyCollision = new class implements CollisionBoxQuery {
            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                return [];
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                return false;
            }
        };

        $resolved = (new DroppedItemCollisionResolver(
            $emptyCollision,
            new FluidCurrentResolver($fluids),
        ))->resolve($entity, $entity->tick(ItemEntityRegistry::GRAVITY, ItemEntityRegistry::DRAG));

        self::assertGreaterThan(0.5, $resolved->position->x);
        self::assertGreaterThan(0.0, $resolved->motion->x);
    }

    private static function ground(): CollisionBoxQuery
    {
        $ground = AxisAlignedBox::unitAt(0, 63, 0);

        return new class ($ground) implements CollisionBoxQuery {
            public function __construct(private AxisAlignedBox $ground) {}

            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                return $this->ground->intersects($area) ? [$this->ground] : [];
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                return $this->ground->intersects($area);
            }
        };
    }
}
