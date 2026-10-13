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

namespace Bedriox\Server\Tests\World\Generation\Noise;

use Bedriox\Server\World\Generation\Noise\OverworldBaseDensitySampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverworldBaseDensitySamplerTest extends TestCase
{
    #[DataProvider('referenceColumn')]
    public function testSeedZeroMatchesUncarvedReferenceColumn(int $y, float $density): void
    {
        self::assertEqualsWithDelta($density, (new OverworldBaseDensitySampler(0))->sample(-48, $y, -32), 0.0002);
    }

    #[DataProvider('selectorWrapReferenceSamples')]
    public function testSelectorVerticalWrapMatchesReferenceDensity(
        int $x,
        int $y,
        int $z,
        float $density,
    ): void {
        self::assertEqualsWithDelta($density, (new OverworldBaseDensitySampler(0))->sample($x, $y, $z), 0.0002);
    }

    public function testBatchedColumnIsExactlyEquivalentToIndividualSamples(): void
    {
        $sampler = new OverworldBaseDensitySampler(123456789);
        $column = $sampler->sampleColumn(-117, 203, -64, 320, 8);

        self::assertCount(49, $column);
        foreach ($column as $index => $density) {
            self::assertSame($sampler->sample(-117, -64 + $index * 8, 203), $density);
        }
    }

    #[DataProvider('invalidColumnBounds')]
    public function testRejectsInvalidBatchedColumnBounds(int $minimumY, int $maximumY, int $step): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new OverworldBaseDensitySampler(0))->sampleColumn(0, 0, $minimumY, $maximumY, $step);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidColumnBounds(): iterable
    {
        yield 'reversed bounds' => [1, 0, 1];
        yield 'zero step' => [0, 1, 0];
        yield 'negative step' => [0, 1, -1];
    }

    /** @return iterable<string, array{int, float}> */
    public static function referenceColumn(): iterable
    {
        yield '64' => [64, -31.261205673217773 / 128.0];
        yield '96' => [96, -121.20242309570312 / 128.0];
        yield '128' => [128, -204.0787811279297 / 128.0];
        yield '160' => [160, -288.15203857421875 / 128.0];
        yield '192' => [192, -389.2140808105469 / 128.0];
        yield '224' => [224, -485.22344970703125 / 128.0];
    }

    /** @return iterable<string, array{int, int, int, float}> */
    public static function selectorWrapReferenceSamples(): iterable
    {
        yield 'coastal transition' => [0, 80, -36, -25.2981834 / 128.0];
        yield 'high terrain transition' => [4, 64, 20, -0.531330109 / 128.0];
        yield 'ridge transition' => [-56, 80, -8, -129.83873 / 128.0];
    }
}
