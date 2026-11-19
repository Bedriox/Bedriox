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

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Runtime\Internal\PackedSessionSet;
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

    /** @var array<int, string> viewer handle => packed visible actor handles */
    private array $visible = [];

    /** @var array<int, string> actor handle => packed viewer handles */
    private array $viewers = [];

    /** @var array<string, int> */
    private array $handles = [];

    /** @var array<int, string> */
    private array $sessions = [];

    /** @var list<int> */
    private array $freeHandles = [];

    private int $nextHandle = 0;

    public function __construct(private readonly int $maximumPlayers)
    {
        if ($maximumPlayers < 1 || $maximumPlayers > 65_535) {
            throw new InvalidArgumentException('Actor visibility capacity is invalid.');
        }
    }

    /** Returns whether the actor's chunk or visible game-mode projection changed. */
    public function upsert(PlayerSnapshot $player): bool
    {
        $previous = $this->players[$player->sessionId] ?? null;
        if ($previous === null && count($this->players) >= $this->maximumPlayers) {
            throw new OverflowException('Actor visibility registry capacity was exceeded.');
        }
        $this->players[$player->sessionId] = $player;
        if ($previous === null) {
            $handle = $this->allocateHandle($player->sessionId);
            $this->visible[$handle] = '';
            $this->viewers[$handle] = '';
        }

        return $previous === null
            || self::chunkCoordinate($previous->position->x) !== self::chunkCoordinate($player->position->x)
            || self::chunkCoordinate($previous->position->z) !== self::chunkCoordinate($player->position->z)
            || $previous->gameMode->isVisible() !== $player->gameMode->isVisible();
    }

    /**
     * @param callable(string, PlayerSnapshot): bool $canViewerSee
     * @return list<PlayerBecameVisible|PlayerBecameHidden>
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
     * @return list<PlayerBecameVisible|PlayerBecameHidden>
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
        $actor = $this->handles[$actorSessionId] ?? null;
        if ($actor === null) {
            return [];
        }
        $viewers = [];
        foreach (PackedSessionSet::handles($this->viewers[$actor]) as $handle) {
            $sessionId = $this->sessions[$handle] ?? null;
            if ($sessionId !== null) {
                $viewers[] = $sessionId;
            }
        }
        sort($viewers, SORT_STRING);

        return $viewers;
    }

    /**
     * Reintroduces an actor to retained viewers after a client lifecycle reset such as respawn.
     *
     * @param list<string> $viewerSessionIds
     * @return list<WorldEvent>
     */
    public function refreshActor(PlayerSnapshot $player, array $viewerSessionIds): array
    {
        if (!isset($this->players[$player->sessionId])) {
            return [];
        }
        $this->players[$player->sessionId] = $player;
        $events = [];
        foreach (array_values(array_unique($viewerSessionIds)) as $viewerSessionId) {
            $actor = $this->handles[$player->sessionId];
            $viewer = $this->handles[$viewerSessionId] ?? null;
            if ($viewer === null || !PackedSessionSet::contains($this->viewers[$actor], $viewer)) {
                continue;
            }
            $events[] = new PlayerBecameHidden($player->sessionId, $player->runtimeActorId, $viewerSessionId);
            $events[] = new PlayerBecameVisible($player, $viewerSessionId);
        }

        return $events;
    }

    /** @return list<string> */
    public function sessionIds(): array
    {
        $sessions = array_keys($this->players);
        sort($sessions, SORT_STRING);

        return $sessions;
    }

    /**
     * @param array<string, true> $chunkKeys
     * @return list<string>
     */
    public function sessionIdsInChunks(array $chunkKeys): array
    {
        if ($chunkKeys === []) {
            return [];
        }
        $sessions = [];
        foreach ($this->players as $sessionId => $player) {
            $key = self::chunkCoordinate($player->position->x) . ':'
                . self::chunkCoordinate($player->position->z);
            if (isset($chunkKeys[$key])) {
                $sessions[] = $sessionId;
            }
        }
        sort($sessions, SORT_STRING);

        return $sessions;
    }

    /** @param callable(string, PlayerSnapshot): bool $canViewerSee */
    public function reconcilePairById(
        string $viewerSessionId,
        string $actorSessionId,
        callable $canViewerSee,
    ): ?WorldEvent {
        $actor = $this->players[$actorSessionId] ?? null;
        if ($actor === null || !isset($this->players[$viewerSessionId]) || $viewerSessionId === $actorSessionId) {
            return null;
        }
        $events = [];
        $this->reconcilePair($viewerSessionId, $actor, $canViewerSee($viewerSessionId, $actor), $events);

        return $events[0] ?? null;
    }

    /**
     * Filters an already ordered recipient list without materializing and sorting
     * the actor's complete viewer list for every transient projection.
     *
     * @param list<string> $recipients
     * @return list<string>
     */
    public function visibleRecipients(string $actorSessionId, array $recipients): array
    {
        $actor = $this->handles[$actorSessionId] ?? null;
        if ($actor === null || $this->viewers[$actor] === '' || $recipients === []) {
            return [];
        }
        $visible = [];
        foreach ($recipients as $recipient) {
            $viewer = $this->handles[$recipient] ?? null;
            if ($viewer !== null && PackedSessionSet::contains($this->viewers[$actor], $viewer)) {
                $visible[] = $recipient;
            }
        }

        return $visible;
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
        $handle = $this->handles[$sessionId];
        $events = [];
        foreach ($this->viewersOf($sessionId) as $viewer) {
            $events[] = new PlayerBecameHidden($sessionId, $player->runtimeActorId, $viewer);
            $viewerHandle = $this->handles[$viewer];
            PackedSessionSet::remove($this->visible[$viewerHandle], $handle);
        }
        foreach (PackedSessionSet::handles($this->visible[$handle]) as $actorHandle) {
            PackedSessionSet::remove($this->viewers[$actorHandle], $handle);
        }
        unset(
            $this->players[$sessionId],
            $this->handles[$sessionId],
            $this->sessions[$handle],
            $this->visible[$handle],
            $this->viewers[$handle],
        );
        $this->freeHandles[] = $handle;

        return $events;
    }

    /** @param list<PlayerBecameVisible|PlayerBecameHidden> $events */
    private function reconcilePair(string $viewer, PlayerSnapshot $actor, bool $shouldBeVisible, array &$events): void
    {
        $viewerHandle = $this->handles[$viewer];
        $actorHandle = $this->handles[$actor->sessionId];
        $isVisible = PackedSessionSet::contains($this->visible[$viewerHandle], $actorHandle);
        if ($shouldBeVisible === $isVisible) {
            return;
        }
        if ($shouldBeVisible) {
            PackedSessionSet::add($this->visible[$viewerHandle], $actorHandle);
            PackedSessionSet::add($this->viewers[$actorHandle], $viewerHandle);
            $events[] = new PlayerBecameVisible($actor, $viewer);

            return;
        }
        PackedSessionSet::remove($this->visible[$viewerHandle], $actorHandle);
        PackedSessionSet::remove($this->viewers[$actorHandle], $viewerHandle);
        $events[] = new PlayerBecameHidden($actor->sessionId, $actor->runtimeActorId, $viewer);
    }

    private function allocateHandle(string $sessionId): int
    {
        $handle = array_pop($this->freeHandles);
        if ($handle === null) {
            $handle = $this->nextHandle++;
        }
        $this->handles[$sessionId] = $handle;
        $this->sessions[$handle] = $sessionId;

        return $handle;
    }

    private static function chunkCoordinate(float $position): int
    {
        return (int) floor($position / 16.0);
    }
}
