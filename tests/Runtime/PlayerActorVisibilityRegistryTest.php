<?php

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
    public function testSourceAndViewerChangesProduceExactlyOneDirectedTransition(): void
    {
        $registry = new PlayerActorVisibilityRegistry(2);
        $registry->upsert($this->player('actor', 1, 0.0));
        $registry->upsert($this->player('viewer', 2, 0.0));

        $shown = $registry->reconcileActor('actor', static fn(string $viewer): bool => $viewer === 'viewer');
        self::assertCount(1, $shown);
        self::assertInstanceOf(PlayerBecameVisible::class, $shown[0]);
        self::assertSame(['viewer'], $registry->viewersOf('actor'));
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

    public function testCapacityIsBounded(): void
    {
        $registry = new PlayerActorVisibilityRegistry(1);
        $registry->upsert($this->player('one', 1, 0.0));
        $this->expectException(OverflowException::class);
        $registry->upsert($this->player('two', 2, 0.0));
    }

    private function player(string $sessionId, int $actorId, float $x): PlayerSnapshot
    {
        return new PlayerSnapshot(
            $sessionId,
            sprintf('00000000-0000-0000-0000-%012d', $actorId),
            $sessionId,
            new Position($x, 64.0, 0.0),
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
