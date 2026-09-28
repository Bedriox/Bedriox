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

use Bedriox\Api\TranslatableMessage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslatableMessageTest extends TestCase
{
    public function testStoresCanonicalKeyAndOrderedParameters(): void
    {
        $message = new TranslatableMessage('death.attack.player', ['Victim', 'Killer']);

        self::assertSame('death.attack.player', $message->key);
        self::assertSame(['Victim', 'Killer'], $message->parameters);
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidMessages(): iterable
    {
        yield 'empty key' => ['', []];
        yield 'noncanonical key' => ['death attack', []];
        yield 'oversized key' => [str_repeat('x', 129), []];
        yield 'too many parameters' => ['key', array_fill(0, 17, 'value')];
        yield 'non-string parameter' => ['key', [1]];
        yield 'invalid UTF-8 parameter' => ['key', ["\xff"]];
        yield 'oversized parameter' => ['key', [str_repeat('x', 1_025)]];
    }

    /** @param array<array-key, mixed> $parameters */
    #[DataProvider('invalidMessages')]
    public function testRejectsInvalidValues(string $key, array $parameters): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TranslatableMessage($key, $parameters);
    }
}
