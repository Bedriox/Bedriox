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

namespace Bedriox\Server\Tests\Tools;

use Bedriox\Tools\VanillaCatalogGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/VanillaCatalogGenerator.php';

final class VanillaCatalogGeneratorTest extends TestCase
{
    public function testRenderingIsDeterministicAndUsesCanonicalValues(): void
    {
        $first = VanillaCatalogGenerator::render(
            'Bedriox\\Api\\World',
            'VanillaBlocks',
            ['minecraft:stone', 'minecraft:oak_door', 'minecraft:stone'],
        );
        $second = VanillaCatalogGenerator::render(
            'Bedriox\\Api\\World',
            'VanillaBlocks',
            ['minecraft:oak_door', 'minecraft:stone'],
        );

        self::assertSame($first, $second);
        self::assertStringContainsString("public const string OAK_DOOR = 'minecraft:oak_door';", $first);
        self::assertStringContainsString("public const string STONE = 'minecraft:stone';", $first);
    }

    public function testRejectsConstantNameCollisions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VanillaCatalogGenerator::render(
            'Bedriox\\Api\\World',
            'VanillaBlocks',
            ['minecraft:example.block', 'minecraft:example-block'],
        );
    }

    public function testHistoricalCanonicalNamesReceiveClearApiConstants(): void
    {
        $catalog = VanillaCatalogGenerator::render(
            'Bedriox\\Api\\World',
            'VanillaBlocks',
            ['minecraft:wooden_door'],
        );

        self::assertStringContainsString(
            "public const string OAK_DOOR = 'minecraft:wooden_door';",
            $catalog,
        );
        self::assertStringNotContainsString('WOODEN_DOOR', $catalog);
    }

    public function testCheckDetectsCurrentAndStaleOutput(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-catalog-');
        self::assertIsString($path);
        try {
            self::assertNotFalse(file_put_contents($path, 'current'));
            self::assertTrue(VanillaCatalogGenerator::isCurrent($path, 'current'));
            self::assertFalse(VanillaCatalogGenerator::isCurrent($path, 'stale'));
        } finally {
            unlink($path);
        }
    }
}
