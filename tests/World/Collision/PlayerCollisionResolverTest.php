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

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\CollisionBoxQuery;
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;
use Bedriox\Server\World\Collision\PlayerCollisionResolver;
use PHPUnit\Framework\TestCase;

final class PlayerCollisionResolverTest extends TestCase
{
    public function testLandingStopsAtFloorAndDerivesGroundedState(): void
    {
        $resolver = new PlayerCollisionResolver(self::query([AxisAlignedBox::unitAt(0, 63, 0)]));
        $result = $resolver->resolve(new Position(0.5, 66.0, 0.5), new Position(0.5, 62.0, 0.5), false);

        self::assertEqualsWithDelta(64.0, $result->position->y, 0.000001);
        self::assertTrue($result->collidedY);
        self::assertTrue($resolver->isGrounded($result->position));
    }

    public function testWallStopsOneAxisWhileDiagonalMovementSlides(): void
    {
        $resolver = new PlayerCollisionResolver(self::query([AxisAlignedBox::unitAt(1, 64, 0)]));
        $result = $resolver->resolve(new Position(0.0, 64.0, 0.0), new Position(2.0, 64.0, 1.0), false);

        self::assertEqualsWithDelta(0.7, $result->position->x, 0.000001);
        self::assertEqualsWithDelta(1.0, $result->position->z, 0.000001);
        self::assertTrue($result->collidedX);
        self::assertFalse($result->collidedZ);
    }

    public function testCeilingStopsUpwardMovementWithoutHorizontalDamage(): void
    {
        $resolver = new PlayerCollisionResolver(self::query([AxisAlignedBox::unitAt(0, 66, 0)]));
        $result = $resolver->resolve(new Position(0.5, 64.0, 0.5), new Position(0.8, 65.0, 0.5), false);

        self::assertEqualsWithDelta(64.2, $result->position->y, 0.000001);
        self::assertEqualsWithDelta(0.8, $result->position->x, 0.000001);
        self::assertTrue($result->collidedY);
        self::assertFalse($result->collidedX);
    }

    public function testGroundedPlayerChoosesClearHalfHeightStep(): void
    {
        $resolver = new PlayerCollisionResolver(self::query([
            new AxisAlignedBox(1.0, 64.0, 0.0, 2.0, 64.5, 1.0),
        ]));
        $result = $resolver->resolve(new Position(0.0, 64.0, 0.5), new Position(1.2, 64.0, 0.5), true);

        self::assertTrue($result->stepped);
        self::assertEqualsWithDelta(1.2, $result->position->x, 0.000001);
        self::assertEqualsWithDelta(64.5, $result->position->y, 0.000001);
    }

    public function testValidatedVerticalCollisionHintUsesGroundedFastPath(): void
    {
        $resolver = new PlayerCollisionResolver(self::query([AxisAlignedBox::unitAt(0, 63, 0)]));
        $result = $resolver->resolve(
            new Position(0.5, 64.0, 0.5),
            new Position(0.7, 64.0, 0.5),
            false,
            true,
        );

        self::assertTrue($result->fastPath);
        self::assertTrue($result->grounded);
        self::assertSame(0, $result->obstacleCount);
        self::assertEqualsWithDelta(0.7, $result->position->x, 0.000001);
    }

    public function testMovementNeverLoadsTerrainSynchronously(): void
    {
        $resolver = new PlayerCollisionResolver(new class implements LoadedCollisionBoxQuery {
            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                throw new \LogicException('The blocking collision path must not be used.');
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                throw new \LogicException('The blocking collision path must not be used.');
            }

            public function boxesIntersectingLoaded(AxisAlignedBox $area): ?array
            {
                return null;
            }
        });
        $from = new Position(15.9, 64.0, 0.5);
        $result = $resolver->resolve($from, new Position(16.1, 64.0, 0.5), true, true);

        self::assertFalse($result->terrainLoaded);
        self::assertSame($from, $result->position);
        self::assertTrue($result->grounded);
        self::assertFalse($result->fastPath);
    }

    /** @param list<AxisAlignedBox> $boxes */
    private static function query(array $boxes): CollisionBoxQuery
    {
        return new class ($boxes) implements CollisionBoxQuery {
            /** @param list<AxisAlignedBox> $boxes */
            public function __construct(private readonly array $boxes) {}

            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                return array_values(array_filter(
                    $this->boxes,
                    static fn(AxisAlignedBox $box): bool => $box->intersects($area),
                ));
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                return $this->boxesIntersecting($area) !== [];
            }
        };
    }
}
