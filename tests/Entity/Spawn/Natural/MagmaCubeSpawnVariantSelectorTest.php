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

namespace Bedriox\Server\Tests\Entity\Spawn\Natural;

use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Server\Entity\Spawn\Natural\MagmaCubeSpawnVariantSelector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class MagmaCubeSpawnVariantSelectorTest extends TestCase
{
    public function testSelectionIsDeterministicAndCoversRegisteredSizes(): void
    {
        $seen = [];
        for ($tick = 0; $tick < 256; ++$tick) {
            $size = MagmaCubeSpawnVariantSelector::select(42, new Position(10.5, 54.0, -3.5), $tick);
            self::assertSame($size, MagmaCubeSpawnVariantSelector::select(42, new Position(10.5, 54.0, -3.5), $tick));
            $seen[$size->value] = true;
        }

        $sizes = array_keys($seen);
        sort($sizes, SORT_NUMERIC);
        self::assertSame([1, 2, 4], $sizes);
        self::assertSame([SlimeSize::SMALL->value, SlimeSize::MEDIUM->value, SlimeSize::LARGE->value], $sizes);
    }
}
