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

namespace Bedriox\Server\Runtime\Scoreboard;

use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot as ApiDisplaySlot;
use Bedriox\Api\Scoreboard\ScoreboardSortOrder as ApiSortOrder;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\RemoveObjectivePacket;
use Bedriox\Protocol\Packet\ScoreboardDisplaySlot;
use Bedriox\Protocol\Packet\ScoreboardEntryType;
use Bedriox\Protocol\Packet\ScoreboardIdentityAction;
use Bedriox\Protocol\Packet\ScoreboardIdentityEntry;
use Bedriox\Protocol\Packet\ScoreboardScoreEntry;
use Bedriox\Protocol\Packet\ScoreboardSortOrder;
use Bedriox\Protocol\Packet\SetDisplayObjectivePacket;
use Bedriox\Protocol\Packet\SetScoreboardIdentityPacket;
use Bedriox\Protocol\Packet\SetScorePacket;
use Bedriox\Server\Plugin\Scoreboard\ObjectiveProjection;
use Bedriox\Server\Plugin\Scoreboard\ObjectiveScoreHolderType;
use Bedriox\Server\Plugin\Scoreboard\ObjectiveScoreProjection;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardDirtyEntry;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardDirtyResource;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardRegistry;
use Bedriox\Server\Plugin\Scoreboard\SidebarProjection;
use Bedriox\Server\Plugin\Scoreboard\SidebarViewProjection;
use Closure;
use LogicException;

/** @internal Converts coalesced plugin scoreboard state into bounded per-viewer packet deltas. */
final class ScoreboardRuntimeProjector
{
    public const int MAXIMUM_DIRTY_ENTRIES_PER_FLUSH = 512;

    /** @var array<string, SidebarProjection> */
    private array $sidebars = [];
    /** @var array<string, array<string, SidebarViewProjection>> Canonical player UUID => resource key => view. */
    private array $views = [];
    /** @var array<string, ObjectiveProjection> */
    private array $objectives = [];
    /** @var array<string, array<string, array{title: string, slots: array<string, array{slot: ScoreboardDisplaySlot, sort: ScoreboardSortOrder}>, entries: array<string, ScoreboardScoreEntry>}>> */
    private array $delivered = [];
    /** @var array<string, true> */
    private array $replay = [];
    /** @var array<string, true> */
    private array $activeViewers = [];
    /** @var array<string, true> */
    private array $connectedActors = [];
    /** @var array<string, true> */
    private array $disconnectedActors = [];
    /** @var array<string, int> */
    private array $scoreIds = [];
    private int $nextScoreId = 1;

    /** @param Closure(string): ?int $playerActorId Resolves a canonical player UUID to its current actor unique ID. */
    public function __construct(private readonly Closure $playerActorId) {}

    public function requestReplay(string $playerUuid): void
    {
        $uuid = strtolower($playerUuid);
        $this->replay[$uuid] = true;
        $this->activeViewers[$uuid] = true;
        $this->connectedActors[$uuid] = true;
    }

    public function forgetPlayer(string $playerUuid): void
    {
        $uuid = strtolower($playerUuid);
        unset(
            $this->delivered[$uuid],
            $this->replay[$uuid],
            $this->activeViewers[$uuid],
            $this->connectedActors[$uuid],
        );
        $this->disconnectedActors[$uuid] = true;
        unset($this->views[$uuid]);
    }

    /** @return list<ScoreboardPacketBatch> */
    public function flush(ScoreboardRegistry $registry): array
    {
        $affected = $this->replay;
        $replayed = $this->replay;
        $this->replay = [];
        foreach (array_keys($this->connectedActors) as $actorUuid) {
            foreach ($this->objectives as $objective) {
                if (!$this->containsPlayerScore($objective, $actorUuid)) {
                    continue;
                }
                foreach ($objective->displays as $display) {
                    $affected[strtolower($display->player->uuid)] = true;
                }
            }
        }
        $this->connectedActors = [];
        foreach (array_keys($this->disconnectedActors) as $actorUuid) {
            foreach ($this->objectives as $objective) {
                if (!$this->containsPlayerScore($objective, $actorUuid)) {
                    continue;
                }
                foreach ($objective->displays as $display) {
                    $affected[strtolower($display->player->uuid)] = true;
                }
            }
        }
        $this->disconnectedActors = [];
        foreach ($registry->drainDirty(self::MAXIMUM_DIRTY_ENTRIES_PER_FLUSH) as $entry) {
            $this->apply($entry, $affected);
        }
        if ($affected === []) {
            return [];
        }

        /** @var array<string, array{packets: non-empty-list<Packet>, viewers: non-empty-list<string>}> $groups */
        $groups = [];
        foreach (array_keys($affected) as $uuid) {
            if (!isset($this->activeViewers[$uuid])) {
                unset($this->delivered[$uuid]);
                continue;
            }
            $desired = $this->desiredFor($uuid);
            $previous = $this->delivered[$uuid] ?? [];
            $packets = isset($replayed[$uuid])
                ? $this->completeReplayPackets($previous, $desired)
                : $this->deltaPackets($previous, $desired);
            $this->delivered[$uuid] = $desired;
            if ($packets === []) {
                continue;
            }
            $signature = hash('sha256', serialize($packets));
            if (!isset($groups[$signature])) {
                $groups[$signature] = ['packets' => $packets, 'viewers' => [$uuid]];
            } else {
                $groups[$signature]['viewers'][] = $uuid;
            }
        }

        return array_values(array_map(
            static fn(array $group): ScoreboardPacketBatch => new ScoreboardPacketBatch(
                $group['viewers'],
                $group['packets'],
            ),
            $groups,
        ));
    }

    /** @param array<string, true> $affected */
    private function apply(ScoreboardDirtyEntry $entry, array &$affected): void
    {
        $key = self::resourceKey($entry->owner, $entry->id);
        if ($entry->resource === ScoreboardDirtyResource::SIDEBAR) {
            if ($entry->projection instanceof SidebarProjection && !$entry->removed) {
                $this->sidebars[$key] = $entry->projection;
            } else {
                unset($this->sidebars[$key]);
                foreach ($this->views as $uuid => $views) {
                    if (isset($views[$key])) {
                        $affected[$uuid] = true;
                        unset($this->views[$uuid][$key]);
                        if ($this->views[$uuid] === []) {
                            unset($this->views[$uuid]);
                        }
                    }
                }
            }
            return;
        }
        if ($entry->resource === ScoreboardDirtyResource::SIDEBAR_VIEW) {
            if (!$entry->projection instanceof SidebarViewProjection || $entry->viewerUuid === null) {
                return;
            }
            $uuid = strtolower($entry->viewerUuid);
            $affected[$uuid] = true;
            if ($entry->removed || !$entry->projection->showing) {
                unset($this->views[$uuid][$key]);
                if (($this->views[$uuid] ?? []) === []) {
                    unset($this->views[$uuid]);
                }
            } else {
                $this->views[$uuid][$key] = $entry->projection;
            }
            return;
        }
        $old = $this->objectives[$key] ?? null;
        if ($old instanceof ObjectiveProjection) {
            foreach ($old->displays as $display) {
                $affected[strtolower($display->player->uuid)] = true;
            }
        }
        if ($entry->projection instanceof ObjectiveProjection && !$entry->removed) {
            $this->objectives[$key] = $entry->projection;
            foreach ($entry->projection->displays as $display) {
                $affected[strtolower($display->player->uuid)] = true;
            }
        } else {
            unset($this->objectives[$key]);
        }
    }

    /**
     * @return array<string, array{title: string, slots: array<string, array{slot: ScoreboardDisplaySlot, sort: ScoreboardSortOrder}>, entries: array<string, ScoreboardScoreEntry>}>
     */
    private function desiredFor(string $uuid): array
    {
        $desired = [];
        foreach ($this->views[$uuid] ?? [] as $resource => $view) {
            if (!$view->showing || $view->removed) {
                continue;
            }
            $wireId = self::wireObjectiveId('s', $resource);
            $entries = [];
            $lineCount = count($view->lines);
            $index = 0;
            foreach ($view->lines as $lineKey => $text) {
                $stableKey = "sidebar\0{$resource}\0{$lineKey}";
                $entries[$stableKey] = ScoreboardScoreEntry::fakePlayer(
                    $this->scoreId($stableKey),
                    $wireId,
                    $lineCount - $index,
                    $text,
                );
                ++$index;
            }
            $desired[$wireId] = [
                'title' => $view->title,
                'slots' => [ApiDisplaySlot::SIDEBAR->value => [
                    'slot' => ScoreboardDisplaySlot::SIDEBAR,
                    'sort' => ScoreboardSortOrder::DESCENDING,
                ]],
                'entries' => $entries,
            ];
        }
        foreach ($this->objectives as $resource => $objective) {
            if ($objective->removed) {
                continue;
            }
            $slots = [];
            foreach ($objective->displays as $display) {
                if (strtolower($display->player->uuid) !== $uuid) {
                    continue;
                }
                $slot = self::protocolSlot($display->slot);
                $slots[$display->slot->value] = [
                    'slot' => $slot,
                    'sort' => self::protocolSortOrder($display->sortOrder),
                ];
            }
            if ($slots === []) {
                continue;
            }
            $wireId = self::wireObjectiveId('o', $resource);
            $entries = [];
            foreach ($objective->scores as $score) {
                $entry = $this->scoreEntry($resource, $wireId, $score);
                if ($entry !== null) {
                    $entries[$this->holderKey($score)] = $entry;
                }
            }
            $desired[$wireId] = ['title' => $objective->displayName, 'slots' => $slots, 'entries' => $entries];
        }

        return $desired;
    }

    private function scoreEntry(
        string $resource,
        string $wireId,
        ObjectiveScoreProjection $projection,
    ): ?ScoreboardScoreEntry {
        $key = "objective\0{$resource}\0" . $this->holderKey($projection);
        $scoreId = $this->scoreId($key);
        if ($projection->holder->type === ObjectiveScoreHolderType::PLAYER) {
            $playerUuid = $projection->holder->playerUuid
                ?? throw new LogicException('A projected player score must contain a player UUID.');
            $actorId = ($this->playerActorId)($playerUuid);
            return $actorId === null
                ? null
                : ScoreboardScoreEntry::player($scoreId, $wireId, $projection->score, $actorId);
        }
        if ($projection->holder->type === ObjectiveScoreHolderType::ENTITY) {
            return ScoreboardScoreEntry::entity(
                $scoreId,
                $wireId,
                $projection->score,
                $projection->holder->entityRuntimeId
                    ?? throw new LogicException('A projected entity score must contain an entity runtime ID.'),
            );
        }

        return ScoreboardScoreEntry::fakePlayer(
            $scoreId,
            $wireId,
            $projection->score,
            $projection->holder->name
                ?? throw new LogicException('A projected named score must contain a name.'),
        );
    }

    private function holderKey(ObjectiveScoreProjection $projection): string
    {
        return $projection->holder->key;
    }

    private function containsPlayerScore(ObjectiveProjection $objective, string $uuid): bool
    {
        foreach ($objective->scores as $score) {
            if ($score->holder->type === ObjectiveScoreHolderType::PLAYER
                && $score->holder->playerUuid === $uuid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{title: string, slots: array<string, array{slot: ScoreboardDisplaySlot, sort: ScoreboardSortOrder}>, entries: array<string, ScoreboardScoreEntry>}> $previous
     * @param array<string, array{title: string, slots: array<string, array{slot: ScoreboardDisplaySlot, sort: ScoreboardSortOrder}>, entries: array<string, ScoreboardScoreEntry>}> $desired
     * @return list<Packet>
     */
    private function completeReplayPackets(array $previous, array $desired): array
    {
        $packets = [];
        foreach ($previous as $wireId => $state) {
            array_push($packets, ...$this->removeObjectivePackets($wireId, $state['entries']));
        }
        foreach ($desired as $wireId => $state) {
            array_push($packets, ...$this->createPackets($wireId, $state));
        }

        return $packets;
    }

    /**
     * @param array<string, array{title: string, slots: array<string, array{slot: ScoreboardDisplaySlot, sort: ScoreboardSortOrder}>, entries: array<string, ScoreboardScoreEntry>}> $previous
     * @param array<string, array{title: string, slots: array<string, array{slot: ScoreboardDisplaySlot, sort: ScoreboardSortOrder}>, entries: array<string, ScoreboardScoreEntry>}> $desired
     * @return list<Packet>
     */
    private function deltaPackets(array $previous, array $desired): array
    {
        $packets = [];
        foreach ($previous as $wireId => $old) {
            if (!isset($desired[$wireId])) {
                array_push($packets, ...$this->removeObjectivePackets($wireId, $old['entries']));
            }
        }
        foreach ($desired as $wireId => $state) {
            $old = $previous[$wireId] ?? null;
            if ($old === null) {
                array_push($packets, ...$this->createPackets($wireId, $state));
                continue;
            }
            if ($old['title'] !== $state['title'] || $old['slots'] !== $state['slots']) {
                array_push($packets, ...$this->removeObjectivePackets($wireId, $old['entries']));
                array_push($packets, ...$this->createPackets($wireId, $state));
                continue;
            }
            $removed = [];
            $clearedIdentities = [];
            foreach ($old['entries'] as $key => $entry) {
                $replacement = $state['entries'][$key] ?? null;
                if ($replacement !== null && !$this->requiresEntryReplacement($entry, $replacement)) {
                    continue;
                }
                $removed[] = ScoreboardScoreEntry::remove($entry->scoreboardId, $wireId);
                if ($entry->type === ScoreboardEntryType::PLAYER) {
                    $clearedIdentities[] = new ScoreboardIdentityEntry($entry->scoreboardId);
                }
            }
            if ($removed !== []) {
                $packets[] = new SetScorePacket($removed);
            }
            if ($clearedIdentities !== []) {
                $packets[] = new SetScoreboardIdentityPacket(
                    ScoreboardIdentityAction::CLEAR,
                    $clearedIdentities,
                );
            }
            $changed = [];
            foreach ($state['entries'] as $key => $entry) {
                if (!isset($old['entries'][$key]) || $old['entries'][$key] != $entry) {
                    $changed[] = $entry;
                }
            }
            array_push($packets, ...$this->scorePackets($changed));
        }

        return $packets;
    }

    /** Bedrock treats a score holder's identity as immutable for the lifetime of its score ID. */
    private function requiresEntryReplacement(
        ScoreboardScoreEntry $previous,
        ScoreboardScoreEntry $next,
    ): bool {
        return $previous->type !== $next->type
            || $previous->actorUniqueId !== $next->actorUniqueId
            || $previous->name !== $next->name;
    }

    /**
     * @param array{title: string, slots: array<string, array{slot: ScoreboardDisplaySlot, sort: ScoreboardSortOrder}>, entries: array<string, ScoreboardScoreEntry>} $state
     * @return list<Packet>
     */
    private function createPackets(string $wireId, array $state): array
    {
        $packets = [];
        foreach ($state['slots'] as $display) {
            $packets[] = new SetDisplayObjectivePacket(
                $display['slot'],
                $wireId,
                $state['title'],
                sortOrder: $display['sort'],
            );
        }
        array_push($packets, ...$this->scorePackets(array_values($state['entries'])));

        return $packets;
    }

    /**
     * @param list<ScoreboardScoreEntry> $entries
     * @return list<Packet>
     */
    private function scorePackets(array $entries): array
    {
        if ($entries === []) {
            return [];
        }
        $identities = [];
        foreach ($entries as $entry) {
            if ($entry->actorUniqueId !== null && $entry->type === ScoreboardEntryType::PLAYER) {
                $identities[] = new ScoreboardIdentityEntry($entry->scoreboardId, $entry->actorUniqueId);
            }
        }
        $packets = [];
        foreach (array_chunk($identities, 1_024) as $identityChunk) {
            $packets[] = new SetScoreboardIdentityPacket(ScoreboardIdentityAction::REGISTER, $identityChunk);
        }
        foreach (array_chunk($entries, 1_024) as $entryChunk) {
            $packets[] = new SetScorePacket($entryChunk);
        }

        return $packets;
    }

    /**
     * @param array<string, ScoreboardScoreEntry> $entries
     * @return list<Packet>
     */
    private function removeObjectivePackets(string $wireId, array $entries): array
    {
        $packets = [new RemoveObjectivePacket($wireId)];
        $identities = [];
        foreach ($entries as $entry) {
            if ($entry->type === ScoreboardEntryType::PLAYER) {
                $identities[] = new ScoreboardIdentityEntry($entry->scoreboardId);
            }
        }
        foreach (array_chunk($identities, 1_024) as $identityChunk) {
            $packets[] = new SetScoreboardIdentityPacket(ScoreboardIdentityAction::CLEAR, $identityChunk);
        }

        return $packets;
    }

    private function scoreId(string $key): int
    {
        if (isset($this->scoreIds[$key])) {
            return $this->scoreIds[$key];
        }
        if ($this->nextScoreId === PHP_INT_MAX) {
            throw new LogicException('Scoreboard score ID capacity has been exhausted.');
        }

        return $this->scoreIds[$key] = $this->nextScoreId++;
    }

    private static function resourceKey(string $owner, string $id): string
    {
        return strtolower($owner) . "\0" . $id;
    }

    private static function wireObjectiveId(string $kind, string $resource): string
    {
        return 'bedriox:' . $kind . ':' . hash('sha256', $resource);
    }

    private static function protocolSlot(ApiDisplaySlot $slot): ScoreboardDisplaySlot
    {
        return match ($slot) {
            ApiDisplaySlot::SIDEBAR => ScoreboardDisplaySlot::SIDEBAR,
            ApiDisplaySlot::PLAYER_LIST => ScoreboardDisplaySlot::PLAYER_LIST,
            ApiDisplaySlot::BELOW_NAME => ScoreboardDisplaySlot::BELOW_NAME,
        };
    }

    private static function protocolSortOrder(ApiSortOrder $order): ScoreboardSortOrder
    {
        return match ($order) {
            ApiSortOrder::ASCENDING => ScoreboardSortOrder::ASCENDING,
            ApiSortOrder::DESCENDING => ScoreboardSortOrder::DESCENDING,
        };
    }
}
