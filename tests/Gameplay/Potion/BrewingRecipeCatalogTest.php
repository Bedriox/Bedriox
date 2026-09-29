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

namespace Bedriox\Server\Tests\Gameplay\Potion;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Potion\BrewingRecipeCatalog;
use PHPUnit\Framework\TestCase;

final class BrewingRecipeCatalogTest extends TestCase
{
    public function testIndexesEveryAdmittedBrewingTransition(): void
    {
        $recipes = BedrockDataSet::bundled()->recipeRegistry();
        $catalog = new BrewingRecipeCatalog($recipes->containerMixes(), $recipes->potionMixes());

        self::assertSame(212, $catalog->count());
        $splash = $catalog->match('minecraft:potion', 22, 'minecraft:gunpowder');
        self::assertNotNull($splash);
        self::assertSame('minecraft:splash_potion', $splash->itemIdentifier);
        self::assertSame(22, $splash->auxValue);

        $trial = $catalog->match('minecraft:potion', 4, 'minecraft:breeze_rod');
        self::assertNotNull($trial);
        self::assertSame('minecraft:potion', $trial->itemIdentifier);
        self::assertSame(43, $trial->auxValue);
        self::assertNull($catalog->match('minecraft:potion', 4, 'minecraft:diamond'));
    }
}
