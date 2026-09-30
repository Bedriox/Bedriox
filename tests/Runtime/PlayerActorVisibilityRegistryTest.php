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

use Bedriox\Server\Runtime\PlayerActorVisibilityRegistry;
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class PlayerActorVisibilityRegistryTest extends TestCase
{
    public function testUpsertOnlyInvalidatesProjectionAcrossChunkOrVisibilityBoundaries(): void
    {
        $registry = new PlayerActorVisibilityRegistry(1);
        self::assertTrue($registry->upsert($this->player('actor', 1, 1.0, 1.0)));
        self::assertFalse($registry->upsert($this->player('actor', 1, 15.9, 15.9)));
        self::assertTrue($registry->upsert($this->player('actor', 1, 16.0, 15.9)));
    }

    public function testSourceAndViewerChangesProduceExactlyOneDirectedTransition(): void
    {
        $registry = new PlayerActorVisibilityRegistry(2);
        $registry->upsert($this->player('actor', 1, 0.0));
        $registry->upsert($this->player('viewer', 2, 0.0));

        $shown = $registry->reconcileActor('actor', static fn(string $viewer): bool => $viewer === 'viewer');
        self::assertCount(1, $shown);
        self::assertInstanceOf(PlayerBecameVisible::class, $shown[0]);
        self::assertSame(['viewer'], $registry->viewersOf('actor'));
        self::assertSame(
            ['viewer'],
            $registry->visibleRecipients('actor', ['missing', 'viewer']),
        );
        self::assertSame([], $registry->reconcileViewer('viewer', static fn(): bool => true));

        $hidden = $registry->reconcileViewer('viewer', static fn(): bool => false);
        self::assertCount(1, $hidden);
        self::assertInstanceOf(PlayerBecameHidden::class, $hidden[0]);
        self::assertSame([], $registry->viewersOf('actor'));
    }

    public function testLatestSnapshotIsUsedWhenActorReentersVisibility(): void
    {
        $registry = new PlayerActorVisibilityRegistry(2);
        $registry->upsert($this->player('actor', 1, 0.0));
        $registry->upsert($this->player('viewer', 2, 0.0));
        $registry->reconcileActor('actor', static fn(): bool => true);
        $registry->reconcileActor('actor', static fn(): bool => false);
        $registry->upsert($this->player('actor', 1, 48.0));

        $shown = $registry->reconcileViewer('viewer', static fn(): bool => true);
        self::assertCount(1, $shown);
        self::assertInstanceOf(PlayerBecameVisible::class, $shown[0]);
        self::assertSame(48.0, $shown[0]->player->position->x);
    }

    public function testChunkSelectionReturnsOnlyActorsAffectedByViewerVisibilityChanges(): void
    {
        $registry = new PlayerActorVisibilityRegistry(4);
        $registry->upsert($this->player('origin-b', 2, 15.9, 15.9));
        $registry->upsert($this->player('east', 3, 16.0, 0.0));
        $registry->upsert($this->player('origin-a', 1, 0.0, 0.0));
        $registry->upsert($this->player('west', 4, -0.1, 0.0));

        self::assertSame(
            ['origin-a', 'origin-b'],
            $registry->sessionIdsInChunks(['0:0' => true]),
        );
        self::assertSame(
            ['east', 'west'],
            $registry->sessionIdsInChunks(['1:0' => true, '-1:0' => true]),
        );
        self::assertSame([], $registry->sessionIdsInChunks([]));
    }

    public function testDisconnectCleansBothDirectionsAndReconnectStartsHidden(): void
    {
        $registry = new PlayerActorVisibilityRegistry(2);
        $registry->upsert($this->player('actor', 1, 0.0));
        $registry->upsert($this->player('viewer', 2, 0.0));
        $registry->reconcileActor('actor', static fn(): bool => true);
        $registry->reconcileActor('viewer', static fn(): bool => true);

        $removed = $registry->remove('actor');
        self::assertCount(1, $removed);
        self::assertInstanceOf(PlayerBecameHidden::class, $removed[0]);
        self::assertSame('viewer', $removed[0]->recipientSessionId);
        self::assertSame([], $registry->viewersOf('actor'));

        $registry->upsert($this->player('actor', 3, 0.0));
        self::assertSame([], $registry->viewersOf('actor'));
    }

    public function testRespawnRefreshReintroducesOnlyRetainedViewers(): void
    {
        $registry = new PlayerActorVisibilityRegistry(3);
        $registry->upsert($this->player('actor', 1, 0.0));
        $registry->upsert($this->player('viewer', 2, 0.0));
        $registry->upsert($this->player('hidden', 3, 0.0));
        $registry->reconcileActor('actor', static fn(string $viewer): bool => $viewer === 'viewer');
        $respawned = $this->player('actor', 1, 8.0);
        $registry->upsert($respawned);

        $events = $registry->refreshActor($respawned, ['viewer', 'hidden', 'viewer']);

        self::assertCount(2, $events);
        self::assertInstanceOf(PlayerBecameHidden::class, $events[0]);
        self::assertInstanceOf(PlayerBecameVisible::class, $events[1]);
        self::assertSame('viewer', $events[0]->recipientSessionId);
        self::assertSame('viewer', $events[1]->recipientSessionId);
        self::assertSame(8.0, $events[1]->player->position->x);
        self::assertSame(['viewer'], $registry->viewersOf('actor'));
    }

    public function testCapacityIsBounded(): void
    {
        $registry = new PlayerActorVisibilityRegistry(1);
        $registry->upsert($this->player('one', 1, 0.0));
        $this->expectException(OverflowException::class);
        $registry->upsert($this->player('two', 2, 0.0));
    }

    private function player(string $sessionId, int $actorId, float $x, float $z = 0.0): PlayerSnapshot
    {
        return new PlayerSnapshot(
            $sessionId,
            sprintf('00000000-0000-0000-0000-%012d', $actorId),
            $sessionId,
            new Position($x, 64.0, $z),
            0.0,
            0.0,
            MovementMode::STOPPED,
            0,
            VerticalState::GROUNDED,
            0.0,
            $actorId,
        );
    }
}
