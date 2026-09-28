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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldOperationResult;
use Bedriox\Api\World\WorldOperationState;
use Bedriox\Api\World\WorldOperationType;
use Bedriox\Server\Runtime\PolledWorldOperationExecutor;
use Bedriox\Server\Runtime\WorldOperationQueue;
use PHPUnit\Framework\TestCase;

final class WorldOperationQueueTest extends TestCase
{
    public function testCompletionCallbacksAreDeferredUntilTheFollowingPoll(): void
    {
        $queue = new WorldOperationQueue();
        $operation = $queue->enqueue(
            WorldOperationType::LOAD,
            'arena',
            static fn(): WorldOperationResult => new WorldOperationResult(
                WorldOperationType::LOAD,
                WorldOperationState::SUCCEEDED,
                'arena',
                new World('arena', 1),
            ),
        );
        $observed = [];
        $operation->onComplete(static function (WorldOperationResult $result) use (&$observed): void {
            $observed[] = $result->worldId;
        });

        self::assertSame(1, $queue->poll());
        self::assertSame(WorldOperationState::SUCCEEDED, $operation->state());
        self::assertSame([], $observed);
        self::assertSame(0, $queue->poll());
        self::assertSame(['arena'], $observed);
    }

    public function testQueuedCancellationNeverExecutesLifecycleWork(): void
    {
        $queue = new WorldOperationQueue();
        $executed = false;
        $operation = $queue->enqueue(
            WorldOperationType::UNLOAD,
            'arena',
            static function () use (&$executed): WorldOperationResult {
                $executed = true;
                throw new \LogicException('Cancelled executor ran.');
            },
        );
        $callbackState = null;
        $operation->onComplete(static function (WorldOperationResult $result) use (&$callbackState): void {
            $callbackState = $result->state;
        });

        self::assertTrue($operation->cancel());
        self::assertSame(1, $queue->poll());
        self::assertFalse($executed);
        self::assertSame(0, $queue->poll());
        self::assertSame(WorldOperationState::CANCELLED, $callbackState);
    }

    public function testExternalLifecycleWorkCanRemainPendingAcrossMainThreadPolls(): void
    {
        $queue = new WorldOperationQueue();
        $polls = 0;
        $operation = $queue->enqueue(
            WorldOperationType::CREATE,
            'arena',
            static fn(): PolledWorldOperationExecutor => new class ($polls) implements PolledWorldOperationExecutor {
                public function __construct(private int &$polls) {}

                public function poll(): ?WorldOperationResult
                {
                    if (++$this->polls < 3) {
                        return null;
                    }

                    return new WorldOperationResult(
                        WorldOperationType::CREATE,
                        WorldOperationState::SUCCEEDED,
                        'arena',
                        new World('arena', 1),
                    );
                }
            },
        );

        self::assertSame(1, $queue->poll());
        self::assertSame(WorldOperationState::RUNNING, $operation->state());
        self::assertSame(1, $queue->poll());
        self::assertSame(WorldOperationState::RUNNING, $operation->state());
        self::assertSame(1, $queue->poll());
        self::assertSame(WorldOperationState::RUNNING, $operation->state());
        self::assertSame(1, $queue->poll());
        self::assertSame(WorldOperationState::SUCCEEDED, $operation->state());
    }
}
