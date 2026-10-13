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

use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Potion\PotionContainer;
use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Gameplay\Potion\PotionCatalog;
use Bedriox\Server\Gameplay\Potion\TippedArrowCatalog;
use PHPUnit\Framework\TestCase;

final class PotionCatalogTest extends TestCase
{
    public function testCurrentPotionMetadataDomainIsComplete(): void
    {
        self::assertSame(range(0, 46), array_map(static fn(PotionType $type): int => $type->value, PotionType::cases()));
        self::assertCount(47, PotionType::cases());
    }

    public function testResolvesEverySupportedContainerWithoutConflatingWireEffectIds(): void
    {
        $catalog = new PotionCatalog();
        foreach (PotionContainer::cases() as $container) {
            $stack = $catalog->resolve($container->value, PotionType::STRONG_HEALING->value);
            self::assertNotNull($stack);
            self::assertSame($container, $stack->container);
            self::assertSame(PotionType::STRONG_HEALING, $stack->type);
            self::assertSame(EffectType::INSTANT_HEALTH, $stack->type->effects()[0]->type);
            self::assertSame(1, $stack->type->effects()[0]->amplifier);
        }
        self::assertNull($catalog->resolve('minecraft:stone', 22));
        self::assertNull($catalog->resolve(PotionContainer::DRINKABLE->value, 47));
    }

    public function testCompoundAndCurrentTrialPotionEffectsAreDefined(): void
    {
        self::assertCount(2, PotionType::TURTLE_MASTER->effects());
        self::assertSame(EffectType::SLOWNESS, PotionType::TURTLE_MASTER->effects()[0]->type);
        self::assertSame(EffectType::RESISTANCE, PotionType::TURTLE_MASTER->effects()[1]->type);
        self::assertSame(EffectType::WIND_CHARGED, PotionType::WIND_CHARGED->effects()[0]->type);
        self::assertSame(EffectType::WEAVING, PotionType::WEAVING->effects()[0]->type);
        self::assertSame(EffectType::OOZING, PotionType::OOZING->effects()[0]->type);
        self::assertSame(EffectType::INFESTED, PotionType::INFESTED->effects()[0]->type);
    }

    public function testTippedArrowMetadataCoversEveryEffectBearingPotion(): void
    {
        $arrows = new TippedArrowCatalog();
        self::assertNull($arrows->resolve(0));
        self::assertNull($arrows->arrowAuxValue(PotionType::AWKWARD));
        foreach (array_slice(PotionType::cases(), 5) as $type) {
            $auxValue = $arrows->arrowAuxValue($type);
            self::assertNotNull($auxValue);
            self::assertSame($type, $arrows->resolve($auxValue));
        }
    }

    public function testCurrentBedrockTippedArrowMetadataUsesTheArrowSpecificOffset(): void
    {
        $arrows = new TippedArrowCatalog();
        $expected = [
            6 => PotionType::NIGHT_VISION,
            7 => PotionType::LONG_NIGHT_VISION,
            8 => PotionType::INVISIBILITY,
            9 => PotionType::LONG_INVISIBILITY,
            10 => PotionType::LEAPING,
            11 => PotionType::LONG_LEAPING,
            12 => PotionType::STRONG_LEAPING,
            13 => PotionType::FIRE_RESISTANCE,
            14 => PotionType::LONG_FIRE_RESISTANCE,
            15 => PotionType::SWIFTNESS,
            16 => PotionType::LONG_SWIFTNESS,
            17 => PotionType::STRONG_SWIFTNESS,
            18 => PotionType::SLOWNESS,
            19 => PotionType::LONG_SLOWNESS,
            20 => PotionType::WATER_BREATHING,
            21 => PotionType::LONG_WATER_BREATHING,
            22 => PotionType::HEALING,
            23 => PotionType::STRONG_HEALING,
            24 => PotionType::HARMING,
            25 => PotionType::STRONG_HARMING,
            26 => PotionType::POISON,
            27 => PotionType::LONG_POISON,
            28 => PotionType::STRONG_POISON,
            29 => PotionType::REGENERATION,
            30 => PotionType::LONG_REGENERATION,
            31 => PotionType::STRONG_REGENERATION,
            32 => PotionType::STRENGTH,
            33 => PotionType::LONG_STRENGTH,
            34 => PotionType::STRONG_STRENGTH,
            35 => PotionType::WEAKNESS,
            36 => PotionType::LONG_WEAKNESS,
            37 => PotionType::WITHER,
            38 => PotionType::TURTLE_MASTER,
            39 => PotionType::LONG_TURTLE_MASTER,
            40 => PotionType::STRONG_TURTLE_MASTER,
            41 => PotionType::SLOW_FALLING,
            42 => PotionType::LONG_SLOW_FALLING,
            43 => PotionType::STRONG_SLOWNESS,
            44 => PotionType::WIND_CHARGED,
            45 => PotionType::WEAVING,
            46 => PotionType::OOZING,
            47 => PotionType::INFESTED,
        ];

        foreach ($expected as $auxValue => $type) {
            self::assertSame($type, $arrows->resolve($auxValue), "Arrow metadata $auxValue resolved incorrectly.");
        }
    }
}
