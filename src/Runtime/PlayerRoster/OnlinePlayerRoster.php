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

namespace Bedriox\Server\Runtime\PlayerRoster;

use Bedriox\Protocol\Packet\PlayerListAddEntry;
use Bedriox\Server\Runtime\Internal\PackedSessionSet;
use InvalidArgumentException;
use LogicException;

/**
 * Server-wide desired player-list state, deliberately independent of world actor visibility.
 *
 * Packed delivery sets make additions, updates, and removals idempotent for each client.
 * Callers acknowledge a plan only after its ordered packets enter that client's queue.
 */
final class OnlinePlayerRoster
{
    /**
     * @var array<string, array{
     *     session: string,
     *     entry: PlayerListAddEntry,
     *     revision: int,
     *     presentTo: string,
     *     currentFor: string
     * }> Canonical UUID => entry and packed viewer delivery sets.
     */
    private array $entries = [];

    /** @var array<string, string> Runtime session ID => canonical UUID. */
    private array $identityBySession = [];

    /** @var array<string, string> Canonical UUID => packed viewers awaiting its removal. */
    private array $departures = [];

    /** @var array<string, int> Runtime session ID => compact viewer handle. */
    private array $viewerHandles = [];

    /** @var list<int> */
    private array $freeViewerHandles = [];

    private int $nextViewerHandle = 0;

    private int $nextRevision = 1;

    public function __construct(private readonly int $maximumEntries)
    {
        if ($maximumEntries < 1) {
            throw new InvalidArgumentException('Player-roster capacity must be positive.');
        }
    }

    /** Registers one completed admission and returns its roster revision. */
    public function admit(string $sessionId, PlayerListAddEntry $entry): int
    {
        $identity = self::identityKey($entry->uuid);
        if (isset($this->identityBySession[$sessionId])) {
            throw new LogicException('A runtime session cannot own multiple player-roster entries.');
        }
        if (isset($this->entries[$identity])) {
            throw new LogicException('An authenticated identity cannot enter the player roster twice.');
        }
        if (count($this->entries) >= $this->maximumEntries) {
            throw new LogicException('The player roster has reached its bounded capacity.');
        }

        $revision = $this->allocateRevision();
        $presentTo = $this->departures[$identity] ?? '';
        unset($this->departures[$identity]);
        $this->entries[$identity] = [
            'session' => $sessionId,
            'entry' => $entry,
            'revision' => $revision,
            'presentTo' => $presentTo,
            'currentFor' => '',
        ];
        $this->identityBySession[$sessionId] = $identity;
        $this->viewerHandle($sessionId);

        return $revision;
    }

    /** Replaces one admitted player's presentation and returns the new roster revision. */
    public function replace(string $sessionId, PlayerListAddEntry $entry): int
    {
        $identity = self::identityKey($entry->uuid);
        $current = $this->entries[$identity] ?? null;
        if ($current === null || $current['session'] !== $sessionId
            || ($this->identityBySession[$sessionId] ?? null) !== $identity) {
            throw new LogicException('Only the roster entry owner may replace its presentation.');
        }

        $revision = $this->allocateRevision();
        $this->entries[$identity] = [
            'session' => $sessionId,
            'entry' => $entry,
            'revision' => $revision,
            'presentTo' => $current['presentTo'],
            'currentFor' => '',
        ];

        return $revision;
    }

    /** Removes an actual online-player departure; world transfers must not call this method. */
    public function remove(string $sessionId, string $uuid): bool
    {
        $identity = self::identityKey($uuid);
        $current = $this->entries[$identity] ?? null;
        if ($current === null) {
            return false;
        }
        if ($current['session'] !== $sessionId || ($this->identityBySession[$sessionId] ?? null) !== $identity) {
            throw new LogicException('A player-roster departure does not match its authoritative session.');
        }

        if ($current['presentTo'] !== '') {
            $this->departures[$identity] = $current['presentTo'];
        }
        unset($this->entries[$identity], $this->identityBySession[$sessionId]);

        return true;
    }

    /**
     * Returns the exact changes needed to make one client's delivered roster equal current server state.
     *
     * @return array{
     *     additions: list<array{identity: string, revision: int, entry: PlayerListAddEntry}>,
     *     removals: list<string>
     * }
     */
    public function plan(string $sessionId): array
    {
        $viewer = $this->viewerHandle($sessionId);
        $additions = [];
        foreach ($this->entries as $identity => $state) {
            if (PackedSessionSet::contains($state['currentFor'], $viewer)) {
                continue;
            }
            $additions[] = [
                'identity' => $identity,
                'revision' => $state['revision'],
                'entry' => $state['entry'],
            ];
        }

        $removals = [];
        foreach ($this->departures as $identity => $viewers) {
            if (PackedSessionSet::contains($viewers, $viewer)) {
                $removals[] = $identity;
            }
        }

        return ['additions' => $additions, 'removals' => $removals];
    }

    /** Marks the owner's self entry, which the local client already applied during bootstrap or skin selection. */
    public function acknowledgeOwner(string $sessionId, string $uuid, int $revision): void
    {
        $identity = self::identityKey($uuid);
        $state = $this->entries[$identity] ?? null;
        if ($state === null || $state['session'] !== $sessionId || $state['revision'] !== $revision) {
            throw new LogicException('A self-entry acknowledgement must match the admitted roster owner.');
        }
        $viewer = $this->viewerHandle($sessionId);
        PackedSessionSet::add($this->entries[$identity]['presentTo'], $viewer);
        PackedSessionSet::add($this->entries[$identity]['currentFor'], $viewer);
    }

    /**
     * @param list<array{identity: string, revision: int, entry: PlayerListAddEntry}> $additions
     */
    public function acknowledgeAdditions(string $sessionId, array $additions): void
    {
        $viewer = $this->viewerHandle($sessionId);
        foreach ($additions as $addition) {
            $state = $this->entries[$addition['identity']] ?? null;
            if ($state !== null && $state['revision'] === $addition['revision']) {
                PackedSessionSet::add($this->entries[$addition['identity']]['presentTo'], $viewer);
                PackedSessionSet::add($this->entries[$addition['identity']]['currentFor'], $viewer);
            }
        }
    }

    /** @param list<string> $identities */
    public function acknowledgeRemovals(string $sessionId, array $identities): void
    {
        $viewer = $this->viewerHandles[$sessionId] ?? null;
        if ($viewer === null) {
            return;
        }
        foreach ($identities as $identity) {
            if (isset($this->entries[$identity])) {
                continue;
            }
            $viewers = $this->departures[$identity] ?? null;
            if ($viewers !== null) {
                PackedSessionSet::remove($viewers, $viewer);
                if ($viewers === '') {
                    unset($this->departures[$identity]);
                } else {
                    $this->departures[$identity] = $viewers;
                }
            }
        }
    }

    public function forgetViewer(string $sessionId): void
    {
        $viewer = $this->viewerHandles[$sessionId] ?? null;
        if ($viewer === null) {
            return;
        }
        foreach ($this->entries as &$state) {
            PackedSessionSet::remove($state['presentTo'], $viewer);
            PackedSessionSet::remove($state['currentFor'], $viewer);
        }
        unset($state);
        foreach ($this->departures as $identity => $viewers) {
            PackedSessionSet::remove($viewers, $viewer);
            if ($viewers === '') {
                unset($this->departures[$identity]);
            } else {
                $this->departures[$identity] = $viewers;
            }
        }
        unset($this->viewerHandles[$sessionId]);
        $this->freeViewerHandles[] = $viewer;
    }

    /** @return list<string> */
    public function viewerSessionIds(): array
    {
        return array_keys($this->identityBySession);
    }

    public function revision(string $uuid): ?int
    {
        return $this->entries[self::identityKey($uuid)]['revision'] ?? null;
    }

    public function entry(string $uuid): ?PlayerListAddEntry
    {
        return $this->entries[self::identityKey($uuid)]['entry'] ?? null;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    private function allocateRevision(): int
    {
        if ($this->nextRevision === PHP_INT_MAX) {
            throw new LogicException('Player-roster revision space is exhausted.');
        }

        return $this->nextRevision++;
    }

    private function viewerHandle(string $sessionId): int
    {
        $handle = $this->viewerHandles[$sessionId] ?? null;
        if ($handle !== null) {
            return $handle;
        }
        $handle = array_pop($this->freeViewerHandles);
        if ($handle === null) {
            if ($this->nextViewerHandle >= $this->maximumEntries) {
                throw new LogicException('The player-roster viewer capacity has been exhausted.');
            }
            $handle = $this->nextViewerHandle++;
        }
        $this->viewerHandles[$sessionId] = $handle;

        return $handle;
    }

    private static function identityKey(string $uuid): string
    {
        return strtolower($uuid);
    }
}
