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

namespace Bedriox\Server\Tests\World\BlockEntity;

use Bedriox\Server\World\BlockEntity\SignBlockEntity;
use Bedriox\Server\World\BlockEntity\SignText;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignBlockEntityTest extends TestCase
{
    public function testRetailSessionIdentityCanOwnTheEditor(): void
    {
        $sign = SignBlockEntity::empty(
            new BlockPosition(1, 64, 2),
            '-8494622548238863521@192.168.1.14:51950',
            true,
            7,
        );

        self::assertSame('-8494622548238863521@192.168.1.14:51950', $sign->editorSessionId);
        self::assertTrue($sign->editorFront);
        self::assertSame(7, $sign->editorRuntimeActorId);
    }

    public function testTextIsNormalizedToFourLinesAndRoundTripsAsBlob(): void
    {
        $text = SignText::fromBlob("one\ntwo", 0xff00ff00, true);
        self::assertSame(['one', 'two', '', ''], $text->lines());
        self::assertSame("one\ntwo", $text->blob());
        self::assertSame(0xff00ff00, $text->color);
        self::assertTrue($text->glowing);
    }

    /** @param array<array-key, mixed> $lines */
    #[DataProvider('invalidTextProvider')]
    public function testMalformedAndOversizedTextIsRejected(array $lines): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SignText($lines);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidTextProvider(): iterable
    {
        yield 'too many lines' => [['', '', '', '', '']];
        yield 'newline in line' => [["one\ntwo"]];
        yield 'oversized line' => [[str_repeat('a', SignText::MAXIMUM_LINE_BYTES + 1)]];
        yield 'non integer index' => [['line' => 'text']];
        yield 'invalid utf8' => [["\xc3\x28"]];
    }

    public function testOnlyOwningSessionCanEditAndWaxingClosesMutation(): void
    {
        $sign = SignBlockEntity::empty(new BlockPosition(1, 64, 2), 'session-1', true, 7);
        $edited = $sign->withText(true, new SignText(['Welcome']), 'session-1');
        self::assertSame('Welcome', $edited->front->lines()[0]);
        self::assertSame(1, $edited->revision);

        $this->expectException(InvalidArgumentException::class);
        $edited->withWaxed(true)->withText(false, new SignText(['No']), 'session-1');
    }

    public function testMismatchedEditorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SignBlockEntity::empty(new BlockPosition(1, 64, 2), 'session-1', true, 7)
            ->withText(true, new SignText(['No']), 'session-2');
    }
}
