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

use Bedriox\Api\TextFormat;
use PHPUnit\Framework\TestCase;

final class TextFormatTest extends TestCase
{
    public function testFormatsCanBeComposedWithoutExposingRawCodes(): void
    {
        self::assertSame("\u{00a7}cError\u{00a7}r", TextFormat::RED . 'Error' . TextFormat::RESET);
        self::assertSame("\u{00a7}9\u{00a7}lBedriox\u{00a7}r", TextFormat::BLUE . TextFormat::BOLD . 'Bedriox' . TextFormat::RESET);
    }

    public function testCompleteCurrentBedrockColorAndStyleSet(): void
    {
        self::assertSame(
            str_split('0123456789abcdefghijmnpqstuv'),
            array_map(static fn(string $format): string => substr($format, strlen(TextFormat::ESCAPE)), TextFormat::COLORS),
        );
        self::assertSame(
            ['k', 'l', 'o'],
            array_map(static fn(string $format): string => substr($format, strlen(TextFormat::ESCAPE)), TextFormat::FORMATS),
        );
        self::assertSame("\u{00a7}r", TextFormat::RESET);
    }
}
