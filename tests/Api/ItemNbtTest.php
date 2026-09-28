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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ItemNbtTest extends TestCase
{
    public function testTypedTagsRoundTripThroughAnImmutableCompound(): void
    {
        $empty = ItemNbt::empty();
        $data = $empty
            ->withString('bedriox:feature', 'popup')
            ->withTag('numbers', Tag::list(TagType::INT, [Tag::int(1), Tag::int(2)]))
            ->withTag('nested', Tag::compound(['enabled' => Tag::byte(1)]));

        self::assertTrue($empty->isEmpty());
        self::assertNull($empty->tag('numbers'));
        self::assertSame('popup', $data->string('bedriox:feature'));
        self::assertSame(TagType::LIST, $data->tag('numbers')?->type());
        $numbers = $data->tag('numbers')->value();
        self::assertIsArray($numbers);
        self::assertCount(2, $numbers);
        self::assertTrue($data->equals(ItemNbt::fromBinary($data->toBinary())));
    }

    public function testOversizedAndMalformedItemNbtAreRejected(): void
    {
        try {
            ItemNbt::empty()->withString('large', str_repeat('x', ItemNbt::MAX_BYTES));
            self::fail('Oversized string must be rejected.');
        } catch (InvalidArgumentException) {
            // The next assertion also checks the decoder's malformed-input boundary.
        }
        $this->expectException(InvalidArgumentException::class);
        ItemNbt::fromBinary("\x0a\x00\x00\x00\x01");
    }

    public function testInventoryBearingUserDataHasBoundedHeadroom(): void
    {
        $data = ItemNbt::empty()->withTag('contents', Tag::byteArray(str_repeat("\x01", 4_096)));

        self::assertGreaterThan(2_048, strlen($data->toBinary()));
        self::assertTrue($data->equals(ItemNbt::fromBinary($data->toBinary())));

        $this->expectException(InvalidArgumentException::class);
        ItemNbt::empty()->withTag('contents', Tag::byteArray(str_repeat("\x01", ItemNbt::MAX_BYTES)));
    }
}
