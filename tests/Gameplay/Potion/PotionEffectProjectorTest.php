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
use Bedriox\Server\Gameplay\Potion\PotionEffectProjector;
use PHPUnit\Framework\TestCase;

final class PotionEffectProjectorTest extends TestCase
{
    public function testSplashUsesVanillaRadiusAndDurationFalloff(): void
    {
        $projector = new PotionEffectProjector();
        self::assertSame(2_700, $projector->splash(PotionType::SWIFTNESS, 0.0)[0]->effect->durationTicks);
        self::assertSame(1_350, $projector->splash(PotionType::SWIFTNESS, 2.0)[0]->effect->durationTicks);
        self::assertSame(2_700, $projector->splash(PotionType::SWIFTNESS, 3.5, true)[0]->effect->durationTicks);
        self::assertSame([], $projector->splash(PotionType::SWIFTNESS, 4.0));
        self::assertSame([], $projector->splash(PotionType::SWIFTNESS, 4.01));
    }

    public function testInstantEffectsRetainIntensityInsteadOfInventingDuration(): void
    {
        $dose = (new PotionEffectProjector())->splash(PotionType::STRONG_HEALING, 2.0)[0];
        self::assertSame(1, $dose->effect->durationTicks);
        self::assertSame(1, $dose->effect->amplifier);
        self::assertSame(0.5, $dose->intensity);
    }

    public function testLingeringAndTippedArrowDurationsAreReduced(): void
    {
        $projector = new PotionEffectProjector();
        self::assertSame(900, $projector->lingering(PotionType::SWIFTNESS)[0]->effect->durationTicks);
        self::assertSame(450, $projector->tippedArrow(PotionType::SWIFTNESS)[0]->effect->durationTicks);
    }
}
