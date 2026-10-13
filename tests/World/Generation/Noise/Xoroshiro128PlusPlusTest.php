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

use Bedriox\Server\World\Generation\Noise\UnsignedLong;
use Bedriox\Server\World\Generation\Noise\XoroshiroPositionalRandomFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Xoroshiro128PlusPlusTest extends TestCase
{
    public function testSeedZeroProducesTheObservedReferencePositionalFactory(): void
    {
        $factory = XoroshiroPositionalRandomFactory::fromSeed(0);

        self::assertSame('2a2ca488f66f517e', $factory->seedLow->toHex());
        self::assertSame('ccbc22d72e97c372', $factory->seedHigh->toHex());
    }

    public function testAquiferCellCentersMatchAllObservedNativeCenters(): void
    {
        $factory = new XoroshiroPositionalRandomFactory(
            UnsignedLong::fromInt(3_102_781_177_767_931_798),
            UnsignedLong::fromInt(7_328_433_467_421_208_117),
        );
        $observed = [
            [-1, 4, 1, -10, 51, 18], [-1, 4, -2, -11, 53, -28], [-1, 5, 0, -11, 60, 4],
            [-1, 3, 0, -12, 38, 0], [-1, 3, 1, -13, 37, 19], [-1, 5, -1, -13, 60, -9],
            [-1, 5, -2, -14, 60, -30], [-1, 4, -1, -15, 48, -14], [-1, 2, 1, -16, 32, 21],
            [-2, 4, 0, -24, 49, 1], [-2, 2, 1, -25, 32, 20], [-2, 4, 1, -25, 54, 21],
            [-2, 4, -1, -26, 50, -7], [-2, 5, -1, -26, 65, -9], [-2, 2, 0, -27, 32, 2],
            [-2, 3, 1, -27, 39, 20], [-2, 5, -2, -30, 60, -26], [-2, 5, 1, -30, 63, 16],
            [-2, 3, 0, -31, 43, 3], [-2, 5, 2, -31, 63, 40], [-2, 3, -2, -32, 36, -29],
            [-2, 3, -1, -32, 38, -10], [-2, 4, -2, -32, 50, -25], [-2, 5, 0, -32, 65, 1],
            [-3, 4, 0, -39, 54, 0], [-3, 3, 1, -40, 43, 22], [-3, 4, -2, -40, 53, -30],
            [-3, 3, -2, -42, 36, -32], [-3, 4, -1, -42, 54, -13], [-3, 3, -1, -43, 43, -7],
            [-3, 5, 1, -43, 62, 21], [-3, 3, 0, -44, 40, 3], [-3, 5, 2, -44, 63, 37],
            [-3, 5, -2, -44, 66, -32], [-3, 4, 1, -45, 50, 19], [-3, 3, 2, -46, 40, 40],
            [-3, 5, -1, -46, 62, -7], [-3, 4, 2, -47, 56, 35], [-3, 5, 0, -48, 64, 1],
            [-1, 3, -1, -7, 43, -16], [-1, 4, 0, -7, 53, 2], [-1, 5, 2, -7, 62, 39],
            [-1, 4, 2, -8, 54, 33], [-1, 5, 1, -9, 61, 16],
        ];

        foreach ($observed as [$gridX, $gridY, $gridZ, $x, $y, $z]) {
            $random = $factory->forBlockCoordinates($gridX, $gridY, $gridZ);
            self::assertSame([$x, $y, $z], [
                $gridX * 16 + $random->nextInt(10),
                $gridY * 12 + $random->nextInt(9),
                $gridZ * 16 + $random->nextInt(10),
            ]);
        }
    }

    /** @param list<string> $expected */
    #[DataProvider('namedStreams')]
    public function testNamedStreamsMatchObservedReferenceOutput(string $name, array $expected): void
    {
        $random = XoroshiroPositionalRandomFactory::fromSeed(0)->forString($name);

        foreach ($expected as $hex) {
            self::assertSame($hex, $random->nextLong()->toHex());
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function namedStreams(): iterable
    {
        yield 'temperature' => ['minecraft:temperature', [
            'b12effcb5327bf6f', '7c1aade8e17af0c2', '270532040debc678', '38cffb27598d9c65',
            'ee11d6e9796067e4', 'f99e6f7a09f89aa5', '1101464289ef975e', '885ecdb254f90c28',
        ]];
        yield 'vegetation' => ['minecraft:vegetation', [
            'abbf46fa30131a09', 'd2934ae0903def31', '256c74502b752701', '8837eb31f3293e3c',
            'ebbed465168f1b14', '027c99f86a8fc73a', 'a9700770dae5ad3c', 'a001d1f0e3182721',
        ]];
        yield 'continentalness' => ['minecraft:continentalness', [
            '6eb32cda42831099', 'f7e4a592e7138f42', '6f3d4198b2bed34f', 'a176d90985e60c61',
            'bf4949d551909805', '2bf64667c85da2c0', 'c805b2d394c450ed', 'e9e9aee54b3952b4',
        ]];
        yield 'erosion' => ['minecraft:erosion', [
            '4cdd91dce5114913', 'b858a65153e5c74f', '0d0b73b585ce47cd', '6790a6d5196ef33a',
            '995bd90922563540', '3aff5bd3601a8413', '9a8b22e753a698bc', '761c2da5756a3854',
        ]];
    }
}
