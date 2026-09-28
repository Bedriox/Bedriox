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

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Server\Worker\WorkerCoreCount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerCoreCountTest extends TestCase
{
    public function testAutoUsesOneLessLogicalProcessorWithAcceptedBounds(): void
    {
        self::assertSame(1, WorkerCoreCount::parse('auto', 1));
        self::assertSame(3, WorkerCoreCount::parse('auto', 4));
        self::assertSame(8, WorkerCoreCount::parse('auto', 64));
    }

    public function testExplicitZeroThroughThirtyTwoAreAccepted(): void
    {
        self::assertSame(0, WorkerCoreCount::parse('0'));
        self::assertSame(32, WorkerCoreCount::parse('32'));
    }

    #[DataProvider('invalidValues')]
    public function testMalformedOrOutOfRangeValuesFail(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkerCoreCount::parse($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        foreach (['AUTO', '', '-1', '+1', '01', '33', '1.0', ' 1'] as $value) {
            yield $value === '' ? 'empty' : $value => [$value];
        }
    }
}
