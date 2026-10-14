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

namespace Bedriox\Server\Tests\Worker\Location;

use Bedriox\Api\World\DimensionType;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\Location\GeneratedFeatureSearchCodec;
use Bedriox\Server\Worker\Location\GeneratedFeatureSearchMode;
use Bedriox\Server\Worker\Location\GeneratedFeatureSearchRequest;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\Worker\Task\LocateGeneratedFeatureTask;
use Bedriox\Server\Worker\WorkerLane;
use Bedriox\Server\Worker\WorkerResultStatus;
use PHPUnit\Framework\TestCase;

final class GeneratedFeatureSearchTaskTest extends TestCase
{
    public function testCodecRoundTripsBoundedRequest(): void
    {
        $request = new GeneratedFeatureSearchRequest(
            GeneratedFeatureSearchMode::STRUCTURE,
            DimensionType::OVERWORLD,
            'minecraft:village',
            -123,
            -422,
            75,
            265,
            ['-2:3'],
        );
        $codec = new GeneratedFeatureSearchCodec();

        self::assertEquals($request, $codec->decodeRequest($codec->encodeRequest($request)));
    }

    public function testWorkerReturnsCanonicalEndBiomeResult(): void
    {
        $request = new GeneratedFeatureSearchRequest(
            GeneratedFeatureSearchMode::BIOME,
            DimensionType::END,
            'minecraft:the_end',
            0,
            120,
            70,
            -80,
        );
        $codec = new GeneratedFeatureSearchCodec();
        $result = $codec->decodeResult((new LocateGeneratedFeatureTask())->execute($codec->encodeRequest($request)));

        self::assertNotNull($result);
        self::assertSame(['minecraft:the_end', 120, 70, -80], [
            $result->identifier,
            $result->x,
            $result->y,
            $result->z,
        ]);
    }

    public function testCoreCatalogKeepsLocateWorkInBoundedWorldLane(): void
    {
        $definition = CoreWorkerTaskCatalog::create()->get(CoreWorkerTaskCatalog::LOCATE_GENERATED_FEATURE);

        self::assertNotNull($definition);
        self::assertSame(WorkerLane::WORLD, $definition->lane);
        self::assertTrue($definition->cancellable);
        self::assertSame(30_000, $definition->timeoutMilliseconds);
    }

    public function testManagedWorkerExecutesLocateTaskOffProcess(): void
    {
        $codec = new GeneratedFeatureSearchCodec();
        $request = new GeneratedFeatureSearchRequest(
            GeneratedFeatureSearchMode::BIOME,
            DimensionType::END,
            'minecraft:the_end',
            0,
            12,
            70,
            -14,
        );
        $pool = ManagedWorkerPool::start('locate-worker-test', 1);
        try {
            $submission = $pool->submit(
                CoreWorkerTaskCatalog::LOCATE_GENERATED_FEATURE,
                $codec->encodeRequest($request),
            );
            self::assertTrue($submission->isAccepted(), $pool->diagnostic());
            $result = null;
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $pool->poll();
                $results = $pool->takeResults(1);
                if ($results !== []) {
                    $result = $results[0];
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);

            self::assertNotNull($result, $pool->diagnostic());
            self::assertSame(WorkerResultStatus::SUCCESS, $result->status, $pool->diagnostic());
            self::assertSame('minecraft:the_end', $codec->decodeResult($result->payload)?->identifier);
        } finally {
            $pool->shutdown();
        }
    }
}
