<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\PlayerSnapshot;
use InvalidArgumentException;
use OverflowException;

/** Bounded directed viewer-to-actor visibility state for joined players. */
final class PlayerActorVisibilityRegistry
{
    /** @var array<string, PlayerSnapshot> */
    private array $players = [];

    /** @var array<string, array<string, true>> viewer session => visible actor sessions */
    private array $visible = [];

    public function __construct(private readonly int $maximumPlayers)
    {
        if ($maximumPlayers < 1 || $maximumPlayers > 65_535) {
            throw new InvalidArgumentException('Actor visibility capacity is invalid.');
        }
    }

    public function upsert(PlayerSnapshot $player): void
    {
        if (!isset($this->players[$player->sessionId]) && count($this->players) >= $this->maximumPlayers) {
            throw new OverflowException('Actor visibility registry capacity was exceeded.');
        }
        $this->players[$player->sessionId] = $player;
        $this->visible[$player->sessionId] ??= [];
    }

    /**
     * @param callable(string, PlayerSnapshot): bool $canViewerSee
     * @return list<WorldEvent>
     */
    public function reconcileActor(string $actorSessionId, callable $canViewerSee): array
    {
        $actor = $this->players[$actorSessionId] ?? null;
        if ($actor === null) {
            return [];
        }
        $viewers = array_keys($this->players);
        sort($viewers, SORT_STRING);
        $events = [];
        foreach ($viewers as $viewer) {
            if ($viewer === $actorSessionId) {
                continue;
            }
            $this->reconcilePair($viewer, $actor, $canViewerSee($viewer, $actor), $events);
        }

        return $events;
    }

    /**
     * @param callable(PlayerSnapshot): bool $canSeeActor
     * @return list<WorldEvent>
     */
    public function reconcileViewer(string $viewerSessionId, callable $canSeeActor): array
    {
        if (!isset($this->players[$viewerSessionId])) {
            return [];
        }
        $actors = $this->players;
        ksort($actors, SORT_STRING);
        $events = [];
        foreach ($actors as $actorSessionId => $actor) {
            if ($actorSessionId === $viewerSessionId) {
                continue;
            }
            $this->reconcilePair($viewerSessionId, $actor, $canSeeActor($actor), $events);
        }

        return $events;
    }

    /** @return list<string> */
    public function viewersOf(string $actorSessionId): array
    {
        $viewers = [];
        foreach ($this->visible as $viewer => $actors) {
            if (isset($actors[$actorSessionId])) {
                $viewers[] = $viewer;
            }
        }
        sort($viewers, SORT_STRING);

        return $viewers;
    }

    /**
     * Removes both directions and returns only removals deliverable to remaining viewers.
     *
     * @return list<WorldEvent>
     */
    public function remove(string $sessionId): array
    {
        $player = $this->players[$sessionId] ?? null;
        if ($player === null) {
            return [];
        }
        $events = [];
        foreach ($this->viewersOf($sessionId) as $viewer) {
            $events[] = new PlayerBecameHidden($sessionId, $player->runtimeActorId, $viewer);
            unset($this->visible[$viewer][$sessionId]);
        }
        unset($this->players[$sessionId], $this->visible[$sessionId]);

        return $events;
    }

    /** @param list<WorldEvent> $events */
    private function reconcilePair(string $viewer, PlayerSnapshot $actor, bool $shouldBeVisible, array &$events): void
    {
        $isVisible = isset($this->visible[$viewer][$actor->sessionId]);
        if ($shouldBeVisible === $isVisible) {
            return;
        }
        if ($shouldBeVisible) {
            $this->visible[$viewer][$actor->sessionId] = true;
            $events[] = new PlayerBecameVisible($actor, $viewer);

            return;
        }
        unset($this->visible[$viewer][$actor->sessionId]);
        $events[] = new PlayerBecameHidden($actor->sessionId, $actor->runtimeActorId, $viewer);
    }
}
