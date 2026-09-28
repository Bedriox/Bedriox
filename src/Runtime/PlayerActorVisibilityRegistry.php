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

    /** @var array<string, array<string, true>> actor session => viewer sessions */
    private array $viewers = [];

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
        $this->visible[$player->sessionId] ??= [];
        $this->viewers[$player->sessionId] ??= [];

        return $previous === null
            || self::chunkCoordinate($previous->position->x) !== self::chunkCoordinate($player->position->x)
            || self::chunkCoordinate($previous->position->z) !== self::chunkCoordinate($player->position->z)
            || $previous->gameMode->isVisible() !== $player->gameMode->isVisible();
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
        $viewers = array_keys($this->viewers[$actorSessionId] ?? []);
        sort($viewers, SORT_STRING);

        return $viewers;
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
        $viewers = $this->viewers[$actorSessionId] ?? [];
        if ($viewers === [] || $recipients === []) {
            return [];
        }
        $visible = [];
        foreach ($recipients as $recipient) {
            if (isset($viewers[$recipient])) {
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
        $events = [];
        foreach ($this->viewersOf($sessionId) as $viewer) {
            $events[] = new PlayerBecameHidden($sessionId, $player->runtimeActorId, $viewer);
            unset($this->visible[$viewer][$sessionId]);
        }
        foreach ($this->visible[$sessionId] as $actorSessionId => $_) {
            unset($this->viewers[$actorSessionId][$sessionId]);
        }
        unset($this->players[$sessionId], $this->visible[$sessionId], $this->viewers[$sessionId]);

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
            $this->viewers[$actor->sessionId][$viewer] = true;
            $events[] = new PlayerBecameVisible($actor, $viewer);

            return;
        }
        unset($this->visible[$viewer][$actor->sessionId]);
        unset($this->viewers[$actor->sessionId][$viewer]);
        $events[] = new PlayerBecameHidden($actor->sessionId, $actor->runtimeActorId, $viewer);
    }

    private static function chunkCoordinate(float $position): int
    {
        return (int) floor($position / 16.0);
    }
}
