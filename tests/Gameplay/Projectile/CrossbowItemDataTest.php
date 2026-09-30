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

namespace Bedriox\Server\Tests\Gameplay\Projectile;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Server\Gameplay\Projectile\CrossbowItemData;
use Bedriox\Server\Player\InventoryStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CrossbowItemData::class)]
final class CrossbowItemDataTest extends TestCase
{
    public function testChargedProjectileRoundTripsAndCanBeCleared(): void
    {
        $arrow = new InventoryStack('minecraft:arrow', 1, 12, damage: 3, nbt: ItemNbt::empty(), auxValue: 7);
        $charged = CrossbowItemData::withChargedProjectile(null, $arrow);
        $decoded = CrossbowItemData::chargedProjectile($charged);

        self::assertNotNull($decoded);
        self::assertSame($arrow->identifier, $decoded->identifier);
        self::assertSame($arrow->damage, $decoded->damage);
        self::assertSame($arrow->auxValue, $decoded->auxValue);
        self::assertNotNull($decoded->nbt);
        $chargedItem = $charged->tag('chargedItem');
        self::assertInstanceOf(Tag::class, $chargedItem);
        self::assertSame(TagType::COMPOUND, $chargedItem->type());
        $values = $chargedItem->value();
        self::assertIsArray($values);
        self::assertInstanceOf(Tag::class, $values['Damage']);
        self::assertSame(TagType::SHORT, $values['Damage']->type());
        self::assertSame(7, $values['Damage']->value());
        self::assertInstanceOf(Tag::class, $values['WasPickedUp']);
        self::assertSame(0, $values['WasPickedUp']->value());
        self::assertNull(CrossbowItemData::withoutChargedProjectile($charged));
    }
}
