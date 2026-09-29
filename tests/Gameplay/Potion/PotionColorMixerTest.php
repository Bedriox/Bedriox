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

use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Gameplay\Potion\PotionColorMixer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PotionColorMixer::class)]
final class PotionColorMixerTest extends TestCase
{
    public function testWaterUsesVanillaEmptyPotionColour(): void
    {
        self::assertSame(0xff385dc6, PotionColorMixer::forPotion(PotionType::WATER));
    }

    public function testTurtleMasterUsesAmplifierWeightedVisibleEffectColours(): void
    {
        // Slowness IV contributes four parts, Resistance III contributes three.
        self::assertSame(0xff755b62, PotionColorMixer::forPotion(PotionType::TURTLE_MASTER));
    }
}
