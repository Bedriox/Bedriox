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

namespace Bedriox\Api\Player;

use Bedriox\Api\World\Position;
use Closure;
use LogicException;

/** @internal Server-owned authoritative action path attached to a live player snapshot. */
final readonly class PlayerActions
{
    /**
     * @param Closure(Position): void            $teleport
     * @param Closure(GameMode): void            $setGameMode
     * @param Closure(float): void    $damage
     */
    public function __construct(
        private Closure $teleport,
        private Closure $setGameMode,
        private Closure $damage,
    ) {}

    public static function unavailable(): self
    {
        $unavailable = static function (): never {
            throw new LogicException('This player snapshot is not attached to an authoritative runtime.');
        };

        return new self($unavailable, $unavailable, $unavailable);
    }

    public function teleport(Position $position): void
    {
        ($this->teleport)($position);
    }

    public function setGameMode(GameMode $gameMode): void
    {
        ($this->setGameMode)($gameMode);
    }

    public function damage(float $amount): void
    {
        ($this->damage)($amount);
    }
}
