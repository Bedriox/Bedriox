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

namespace Bedriox\Server\Runtime\BossBar;

use Bedriox\Server\Simulation\Event\EnderDragonBossBarAction;
use Bedriox\Server\Simulation\Event\EnderDragonBossBarChanged;

/** Ensures an actor-backed boss bar is never sent before its backing actor. */
final class ActorBackedBossBarDelivery
{
    /** @var array<string, array<int, array<string, EnderDragonBossBarChanged>>> */
    private array $pending = [];

    /**
     * @param array<string, true> $actorViewers
     */
    public function admit(
        string $worldId,
        EnderDragonBossBarChanged $event,
        array $actorViewers,
    ): ?EnderDragonBossBarChanged {
        if ($event->action === EnderDragonBossBarAction::REMOVE) {
            foreach ($event->recipientSessionIds as $recipient) {
                unset($this->pending[$worldId][$event->dragonRuntimeId][$recipient]);
            }
            $this->prune($worldId, $event->dragonRuntimeId);

            return $event;
        }

        $eligible = [];
        foreach ($event->recipientSessionIds as $recipient) {
            if (!isset($actorViewers[$recipient])
                || isset($this->pending[$worldId][$event->dragonRuntimeId][$recipient])) {
                $this->pending[$worldId][$event->dragonRuntimeId][$recipient] = $this->createSnapshot(
                    $event,
                    $recipient,
                );
                continue;
            }
            $eligible[] = $recipient;
        }

        return $eligible === [] ? null : $this->withRecipients($event, $eligible);
    }

    /**
     * @param list<string> $recipients
     * @return list<EnderDragonBossBarChanged>
     */
    public function actorAppeared(string $worldId, int $runtimeId, array $recipients): array
    {
        $ready = [];
        foreach ($recipients as $recipient) {
            $pending = $this->pending[$worldId][$runtimeId][$recipient] ?? null;
            if ($pending === null) {
                continue;
            }
            unset($this->pending[$worldId][$runtimeId][$recipient]);
            $ready[] = $pending;
        }
        $this->prune($worldId, $runtimeId);

        return $ready;
    }

    public function removeViewer(string $sessionId): void
    {
        foreach ($this->pending as $worldId => $actors) {
            foreach ($actors as $runtimeId => $viewers) {
                unset($this->pending[$worldId][$runtimeId][$sessionId]);
                $this->prune($worldId, $runtimeId);
            }
        }
    }

    private function createSnapshot(EnderDragonBossBarChanged $event, string $recipient): EnderDragonBossBarChanged
    {
        return new EnderDragonBossBarChanged(
            $event->dragonRuntimeId,
            EnderDragonBossBarAction::CREATE,
            $event->progress,
            [$recipient],
            $event->title,
            $event->color,
            $event->style,
        );
    }

    /** @param list<string> $recipients */
    private function withRecipients(
        EnderDragonBossBarChanged $event,
        array $recipients,
    ): EnderDragonBossBarChanged {
        return new EnderDragonBossBarChanged(
            $event->dragonRuntimeId,
            $event->action,
            $event->progress,
            $recipients,
            $event->title,
            $event->color,
            $event->style,
        );
    }

    private function prune(string $worldId, int $runtimeId): void
    {
        if (($this->pending[$worldId][$runtimeId] ?? []) === []) {
            unset($this->pending[$worldId][$runtimeId]);
        }
        if (($this->pending[$worldId] ?? []) === []) {
            unset($this->pending[$worldId]);
        }
    }
}
