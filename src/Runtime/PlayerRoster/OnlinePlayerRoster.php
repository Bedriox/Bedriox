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
use InvalidArgumentException;
use LogicException;

/**
 * Server-wide desired player-list state, deliberately independent of world actor visibility.
 *
 * The delivered revision ledger makes additions, updates, and removals idempotent for each
 * client. Callers acknowledge a plan only after its ordered packets enter that client's queue.
 */
final class OnlinePlayerRoster
{
    /** @var array<string, array{session: string, entry: PlayerListAddEntry, revision: int}> Canonical UUID => entry. */
    private array $entries = [];

    /** @var array<string, string> Runtime session ID => canonical UUID. */
    private array $identityBySession = [];

    /** @var array<string, array<string, int>> Runtime session ID => canonical UUID => delivered revision. */
    private array $delivered = [];

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
        $this->entries[$identity] = ['session' => $sessionId, 'entry' => $entry, 'revision' => $revision];
        $this->identityBySession[$sessionId] = $identity;
        $this->delivered[$sessionId] ??= [];

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
        $this->entries[$identity] = ['session' => $sessionId, 'entry' => $entry, 'revision' => $revision];

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
        $delivered = $this->delivered[$sessionId] ?? [];
        $additions = [];
        foreach ($this->entries as $identity => $state) {
            if (($delivered[$identity] ?? null) === $state['revision']) {
                continue;
            }
            $additions[] = [
                'identity' => $identity,
                'revision' => $state['revision'],
                'entry' => $state['entry'],
            ];
        }

        $removals = [];
        foreach (array_keys($delivered) as $identity) {
            if (!isset($this->entries[$identity])) {
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
        $this->delivered[$sessionId][$identity] = $revision;
    }

    /**
     * @param list<array{identity: string, revision: int, entry: PlayerListAddEntry}> $additions
     */
    public function acknowledgeAdditions(string $sessionId, array $additions): void
    {
        $this->delivered[$sessionId] ??= [];
        foreach ($additions as $addition) {
            $state = $this->entries[$addition['identity']] ?? null;
            if ($state !== null && $state['revision'] === $addition['revision']) {
                $this->delivered[$sessionId][$addition['identity']] = $addition['revision'];
            }
        }
    }

    /** @param list<string> $identities */
    public function acknowledgeRemovals(string $sessionId, array $identities): void
    {
        foreach ($identities as $identity) {
            if (!isset($this->entries[$identity])) {
                unset($this->delivered[$sessionId][$identity]);
            }
        }
    }

    public function forgetViewer(string $sessionId): void
    {
        unset($this->delivered[$sessionId]);
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

    private static function identityKey(string $uuid): string
    {
        return strtolower($uuid);
    }
}
