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

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Closure;
use Throwable;

/** Resolves authenticated profiles and retains failed authoritative saves for bounded retry. */
final class PlayerPersistenceManager
{
    /** @var array<string, array{player: Player, profile: PlayerBootstrap, revision: int}> */
    private array $pending = [];

    /** @param Closure(): int|null $clock */
    public function __construct(
        private readonly PlayerDataStore $store,
        private readonly string $worldName,
        private readonly Position $defaultSpawn,
        private readonly FixedFlatBlockPalette $palette,
        ?Closure $clock = null,
        private readonly string $defaultGamemode = 'survival',
        private readonly ?ItemCatalog $itemCatalog = null,
    ) {
        GameMode::from($this->defaultGamemode);
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function load(AuthenticatedLogin $login): PlayerBootstrap
    {
        $uuid = strtolower($login->identity);
        $saved = $this->store->load($uuid);
        $now = ($this->clock)();
        if ($saved === null) {
            return new PlayerBootstrap(
                new PlayerIdentity($uuid, $login->displayName, $login->xuid),
                $this->worldName,
                $this->defaultSpawn,
                0.0,
                0.0,
                PlayerInventory::starter($this->palette, $this->itemCatalog)->exportState(),
                $now,
                $now,
                $this->defaultGamemode,
            );
        }

        $savedAlive = $saved->health > 0.0;
        $restoreLocation = $savedAlive;

        return new PlayerBootstrap(
            new PlayerIdentity($uuid, $login->displayName, $login->xuid),
            $restoreLocation ? $saved->worldName : $this->worldName,
            $restoreLocation ? $saved->position : $this->defaultSpawn,
            $restoreLocation ? $saved->yaw : 0.0,
            $restoreLocation ? $saved->pitch : 0.0,
            $saved->inventory,
            $saved->firstPlayedAt,
            $now,
            $saved->gamemode,
            $savedAlive ? $saved->health : \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH,
            $savedAlive ? $saved->food : \Bedriox\Server\Player\PlayerVitals::MAX_FOOD,
            $savedAlive ? $saved->saturation : \Bedriox\Server\Player\PlayerVitals::MAX_SATURATION,
            $savedAlive ? $saved->exhaustion : 0.0,
        );
    }

    public function snapshot(Player $player): PlayerBootstrap
    {
        return new PlayerBootstrap(
            $player->identity,
            $player->worldName(),
            $player->movement->position,
            $player->movement->yaw,
            $player->movement->pitch,
            $player->inventory->exportState(),
            $player->firstPlayedAt,
            ($this->clock)(),
            $player->gamemode,
            $player->vitals->health,
            $player->vitals->food,
            $player->vitals->saturation,
            $player->vitals->exhaustion,
        );
    }

    public function save(Player $player): bool
    {
        if ($this->store instanceof AsynchronousPlayerDataStore) {
            $this->collectAsynchronousCompletions();
            if (!$player->isDirty()) {
                return true;
            }
            $revision = $player->stateRevision();
            $profile = $this->snapshot($player);
            $this->pending[$player->identity->uuid] = compact('player', 'profile', 'revision');
            $this->store->enqueueSave($profile, $revision);

            return false;
        }
        $revision = $player->stateRevision();
        $profile = $this->snapshot($player);
        try {
            $this->store->save($profile);
            $player->acknowledgeSaved($revision);
            unset($this->pending[$player->identity->uuid]);

            return true;
        } catch (Throwable) {
            $this->pending[$player->identity->uuid] = compact('player', 'profile', 'revision');

            return false;
        }
    }

    /** @phpstan-impure */
    public function retryPending(int $budget): int
    {
        if ($this->store instanceof AsynchronousPlayerDataStore) {
            $saved = $this->collectAsynchronousCompletions($budget);
            $attempted = 0;
            foreach ($this->pending as $entry) {
                if ($saved + $attempted >= $budget) {
                    break;
                }
                $this->store->enqueueSave($entry['profile'], $entry['revision']);
                ++$attempted;
            }

            return $saved;
        }
        $saved = 0;
        foreach (array_keys($this->pending) as $uuid) {
            if ($saved >= $budget) {
                break;
            }
            $entry = $this->pending[$uuid];
            try {
                $this->store->save($entry['profile']);
                $entry['player']->acknowledgeSaved($entry['revision']);
                unset($this->pending[$uuid]);
                ++$saved;
            } catch (Throwable) {
                // The last authoritative snapshot remains queued for a later bounded retry.
            }
        }

        return $saved;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function close(int $timeoutMilliseconds = 30_000): bool
    {
        if (!$this->store instanceof AsynchronousPlayerDataStore) {
            return $this->pending === [];
        }
        foreach ($this->pending as $entry) {
            $this->store->enqueueSave($entry['profile'], $entry['revision']);
        }
        $successful = true;
        foreach ($this->store->drainSaves($timeoutMilliseconds) as $completion) {
            $successful = $this->applyAsynchronousCompletion($completion) && $successful;
        }
        $this->store->close();

        return $successful && $this->pending === [];
    }

    private function collectAsynchronousCompletions(int $maximum = 256): int
    {
        if (!$this->store instanceof AsynchronousPlayerDataStore) {
            return 0;
        }
        $saved = 0;
        foreach ($this->store->pollSaves($maximum) as $completion) {
            if ($this->applyAsynchronousCompletion($completion)) {
                ++$saved;
            }
        }

        return $saved;
    }

    private function applyAsynchronousCompletion(\Bedriox\Server\Persistence\PersistenceWriteCompletion $completion): bool
    {
        if (!$completion->successful || !str_starts_with($completion->key, 'player:')) {
            return false;
        }
        $uuid = substr($completion->key, 7);
        $entry = $this->pending[$uuid] ?? null;
        if ($entry === null || $completion->revision > $entry['player']->stateRevision()) {
            return false;
        }
        $entry['player']->acknowledgeSaved($completion->revision);
        if ($completion->revision === $entry['revision']) {
            unset($this->pending[$uuid]);
        }

        return true;
    }
}
