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

namespace Bedriox\Server\Tests\Gameplay\Block\Environment;

use Bedriox\Server\Gameplay\Block\Environment\FallingBlockSupportResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FallingBlockSupportResolverTest extends TestCase
{
    #[DataProvider('replaceableProvider')]
    public function testFallingBlocksPassThroughNonSupportingBlocks(string $identifier): void
    {
        self::assertTrue((new FallingBlockSupportResolver())->canFallThrough($identifier));
    }

    /** @return iterable<string, array{string}> */
    public static function replaceableProvider(): iterable
    {
        foreach (['minecraft:air', 'minecraft:water', 'minecraft:torch', 'minecraft:soul_torch',
            'minecraft:oak_sapling', 'minecraft:activator_rail', 'minecraft:snow_layer'] as $identifier) {
            yield $identifier => [$identifier];
        }
    }

    public function testSolidBlocksRemainSupports(): void
    {
        $resolver = new FallingBlockSupportResolver();

        self::assertFalse($resolver->canFallThrough('minecraft:stone'));
        self::assertFalse($resolver->canFallThrough('minecraft:oak_planks'));
    }
}
