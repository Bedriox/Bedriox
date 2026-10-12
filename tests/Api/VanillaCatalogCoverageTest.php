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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Inventory\VanillaItems;
use Bedriox\Api\World\VanillaBlocks;
use Bedriox\Data\BedrockDataSet;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VanillaCatalogCoverageTest extends TestCase
{
    public function testEveryActiveVanillaBlockIdentifierIsExposedExactlyOnce(): void
    {
        $expected = [];
        foreach (BedrockDataSet::bundled()->blockStateRegistry()->states() as $state) {
            $expected[$state->identifier()] = true;
        }
        $expected = array_keys($expected);
        sort($expected, SORT_STRING);

        $actual = self::catalogValues(VanillaBlocks::class);
        sort($actual, SORT_STRING);

        self::assertSame($expected, $actual);
        self::assertCount(count(array_unique($actual)), $actual);
    }

    public function testEveryActiveVanillaItemIdentifierIsExposedExactlyOnce(): void
    {
        $expected = array_keys(BedrockDataSet::bundled()->itemNetworkRegistry()->definitions());
        sort($expected, SORT_STRING);

        $actual = self::catalogValues(VanillaItems::class);
        sort($actual, SORT_STRING);

        self::assertSame($expected, $actual);
        self::assertCount(count(array_unique($actual)), $actual);
    }

    /**
     * @param class-string $className
     * @return list<string>
     */
    private static function catalogValues(string $className): array
    {
        $values = [];
        foreach ((new ReflectionClass($className))->getConstants() as $value) {
            if (!is_string($value)) {
                self::fail('Vanilla catalog constants must contain canonical string identifiers.');
            }
            $values[] = $value;
        }

        return $values;
    }
}
