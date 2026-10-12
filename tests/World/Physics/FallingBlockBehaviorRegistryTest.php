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

namespace Bedriox\Server\Tests\World\Physics;

use Bedriox\Server\World\Physics\FallingBlockBehaviorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FallingBlockBehaviorRegistryTest extends TestCase
{
    #[DataProvider('newFallingBlockProvider')]
    public function testArchaeologyAndSulfurBlocksUseFallingPhysics(string $identifier): void
    {
        self::assertTrue(FallingBlockBehaviorRegistry::vanilla()->isFalling($identifier));
    }

    /** @return iterable<string, array{string}> */
    public static function newFallingBlockProvider(): iterable
    {
        yield 'suspicious sand' => ['minecraft:suspicious_sand'];
        yield 'suspicious gravel' => ['minecraft:suspicious_gravel'];
        yield 'sulfur spike' => ['minecraft:sulfur_spike'];
    }
}
