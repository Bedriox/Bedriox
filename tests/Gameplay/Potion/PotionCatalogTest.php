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
}
