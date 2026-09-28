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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PluginInfrastructureTest extends TestCase
{
    public function testExecutionContextPreservesNestedAttribution(): void
    {
        $context = new PluginExecutionContext(2);
        $outer = new PluginExecutionFrame('Outer', '1.0.0', 'enable');
        $inner = new PluginExecutionFrame('Inner', '2.0.0', 'event-listener');

        $context->enter($outer);
        $context->enter($inner);

        self::assertSame($inner, $context->current());
        self::assertSame([$outer, $inner], $context->snapshot());

        $this->expectException(PluginException::class);
        try {
            $context->enter(new PluginExecutionFrame('Third', '1.0.0', 'event-listener'));
        } finally {
            $context->leave();
            $context->leave();
            self::assertSame([], $context->snapshot());
        }
    }

    public function testCommitFailureRemainsDiscardableWithoutMaskingOriginalFailure(): void
    {
        $buffer = new PluginActionBuffer();
        $buffer->begin();
        $buffer->stage(static function (): void {
            throw new RuntimeException('commit failed');
        });

        try {
            $buffer->commit();
            self::fail('A failing staged action was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('commit failed', $exception->getMessage());
            self::assertTrue($buffer->isCapturing());
            $buffer->discard();
            self::assertFalse($buffer->isCapturing());
        }
    }

    public function testOwnershipCleanupRunsInReverseOrderAndContainsFailures(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $order = [];
        $ownership->own('Example', 'first', static function () use (&$order): void {
            $order[] = 'first';
        });
        $ownership->own('Example', 'second', static function () use (&$order): void {
            $order[] = 'second';
            throw new RuntimeException('cleanup failed');
        });

        $failures = $ownership->releaseAll('Example');

        self::assertSame(['second', 'first'], $order);
        self::assertCount(1, $failures);
        self::assertSame(0, $ownership->count('Example'));
    }
}
