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

namespace Bedriox\Server\Tests\Gameplay\Explosion;

use Bedriox\Server\Gameplay\Explosion\Planning\ExplosionPlanningService;
use Bedriox\Server\Gameplay\Explosion\Planning\ExplosionWorldView;
use Bedriox\Server\Gameplay\Explosion\Value\ExplosionBlockSample;
use Bedriox\Server\Gameplay\Explosion\Value\ExplosionRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExplosionPlanningServiceTest extends TestCase
{
    public function testBlockBreakingDisabledProducesAnEmptyPlanWithoutSampling(): void
    {
        $samples = 0;
        $world = new CallbackExplosionWorldView(static function () use (&$samples): ExplosionBlockSample {
            ++$samples;

            return new ExplosionBlockSample('minecraft:stone', 6.0);
        });

        $plan = (new ExplosionPlanningService())->plan(
            new ExplosionRequest(new Position(0.5, 64.5, 0.5), 3.0, false),
            $world,
        );

        self::assertSame([], $plan->affectedBlocks);
        self::assertSame([], $plan->ignitionCandidates);
        self::assertFalse($plan->truncated);
        self::assertSame(0, $samples);
    }

    public function testPlanningIsDeterministicAndFindsAirAboveAffectedBlock(): void
    {
        $world = new CallbackExplosionWorldView(static function (BlockPosition $position): ExplosionBlockSample {
            return $position->x === 0 && $position->y === 64 && $position->z === 0
                ? new ExplosionBlockSample('minecraft:stone', 6.0)
                : ExplosionBlockSample::air();
        });
        $request = new ExplosionRequest(new Position(0.5, 64.5, 0.5), 3.0, true, 0.25);
        $service = new ExplosionPlanningService();

        $first = $service->plan($request, $world);
        $second = $service->plan($request, $world);

        self::assertEquals($first, $second);
        self::assertEquals([new BlockPosition(0, 64, 0)], $first->affectedBlocks);
        self::assertEquals([new BlockPosition(0, 65, 0)], $first->ignitionCandidates);
        self::assertFalse($first->truncated);
    }

    public function testUnavailableWorldDataFailsClosed(): void
    {
        $world = new CallbackExplosionWorldView(static fn(): null => null);

        $plan = (new ExplosionPlanningService())->plan(
            new ExplosionRequest(new Position(0.5, 64.5, 0.5), 3.0),
            $world,
        );

        self::assertSame([], $plan->affectedBlocks);
        self::assertFalse($plan->truncated);
    }

    public function testAffectedBlockLimitIsEnforcedAndReported(): void
    {
        $world = new CallbackExplosionWorldView(
            static fn(): ExplosionBlockSample => new ExplosionBlockSample('minecraft:dirt', 0.0),
        );

        $plan = (new ExplosionPlanningService(4))->plan(
            new ExplosionRequest(new Position(0.5, 64.5, 0.5), 3.0),
            $world,
        );

        self::assertCount(4, $plan->affectedBlocks);
        self::assertTrue($plan->truncated);
    }

    public function testRequestAndBlockSamplesRejectInvalidValues(): void
    {
        try {
            new ExplosionRequest(new Position(0.0, 64.0, 0.0), 0.0);
            self::fail('A zero-radius explosion must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new ExplosionBlockSample('minecraft:air', 1.0, true);
    }
}

final readonly class CallbackExplosionWorldView implements ExplosionWorldView
{
    /** @param Closure(BlockPosition): (?ExplosionBlockSample) $sample */
    public function __construct(private Closure $sample) {}

    public function sample(BlockPosition $position): ?ExplosionBlockSample
    {
        return ($this->sample)($position);
    }
}
