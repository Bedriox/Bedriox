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

namespace Bedriox\Server\Gameplay\Portal;

use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

final class PortalContactTracker
{
    public const int REQUIRED_CONTACT_TICKS = 80;
    public const int TRANSFER_COOLDOWN_TICKS = 300;
    private const int MAXIMUM_SESSIONS = 2_048;

    /** @var array<string, array{ticks: int, cooldownUntil: int, entry: BlockPosition|null}> */
    private array $states = [];

    public function contact(string $sessionId, BlockPosition $entry, int $currentTick, bool $instant = false): bool
    {
        $this->validateSession($sessionId);
        if (!isset($this->states[$sessionId]) && count($this->states) >= self::MAXIMUM_SESSIONS) {
            return false;
        }
        $state = $this->states[$sessionId] ?? ['ticks' => 0, 'cooldownUntil' => 0, 'entry' => null];
        if ($state['cooldownUntil'] > $currentTick) {
            $state['ticks'] = 0;
            $state['entry'] = $entry;
            $this->states[$sessionId] = $state;

            return false;
        }
        $state['entry'] = $entry;
        $state['ticks'] = min(self::REQUIRED_CONTACT_TICKS, $state['ticks'] + 1);
        if (!$instant && $state['ticks'] < self::REQUIRED_CONTACT_TICKS) {
            $this->states[$sessionId] = $state;

            return false;
        }
        $state['ticks'] = 0;
        $state['cooldownUntil'] = $currentTick + self::TRANSFER_COOLDOWN_TICKS;
        $this->states[$sessionId] = $state;

        return true;
    }

    public function leave(string $sessionId): void
    {
        $state = $this->states[$sessionId] ?? null;
        if ($state === null) {
            return;
        }
        $state['ticks'] = max(0, $state['ticks'] - 4);
        if ($state['ticks'] === 0 && $state['cooldownUntil'] === 0) {
            unset($this->states[$sessionId]);
        } else {
            $this->states[$sessionId] = $state;
        }
    }

    /** Releases a speculative cooldown when a cancellable pre-event rejects the transfer. */
    public function retryNextTick(string $sessionId, int $currentTick): void
    {
        $state = $this->states[$sessionId] ?? null;
        if ($state === null) {
            return;
        }
        $state['ticks'] = self::REQUIRED_CONTACT_TICKS - 1;
        $state['cooldownUntil'] = $currentTick;
        $this->states[$sessionId] = $state;
    }

    /** Applies the arrival-side cooldown after a portal transfer commits in another world simulation. */
    public function beginArrivalCooldown(string $sessionId, int $currentTick): void
    {
        $this->validateSession($sessionId);
        if (!isset($this->states[$sessionId]) && count($this->states) >= self::MAXIMUM_SESSIONS) {
            return;
        }
        $this->states[$sessionId] = [
            'ticks' => 0,
            'cooldownUntil' => $currentTick + self::TRANSFER_COOLDOWN_TICKS,
            'entry' => null,
        ];
    }

    public function forget(string $sessionId): void
    {
        unset($this->states[$sessionId]);
    }

    private function validateSession(string $sessionId): void
    {
        if ($sessionId === '' || strlen($sessionId) > 128) {
            throw new InvalidArgumentException('Portal contact session identity must be non-empty and bounded.');
        }
    }
}
