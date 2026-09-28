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

/** Stable gameplay modes exposed to plugins without leaking protocol numeric IDs. */
enum GameMode: string
{
    case SURVIVAL = 'survival';
    case CREATIVE = 'creative';
    case ADVENTURE = 'adventure';
    case SPECTATOR = 'spectator';

    public function allowsFlight(): bool
    {
        return $this === self::CREATIVE || $this === self::SPECTATOR;
    }

    public function hasCollision(): bool
    {
        return $this !== self::SPECTATOR;
    }

    public function canBuild(): bool
    {
        return $this === self::SURVIVAL || $this === self::CREATIVE;
    }

    public function instantlyBreaksBlocks(): bool
    {
        return $this === self::CREATIVE;
    }

    public function consumesItems(): bool
    {
        return $this === self::SURVIVAL || $this === self::ADVENTURE;
    }

    public function takesDamage(): bool
    {
        return $this === self::SURVIVAL || $this === self::ADVENTURE;
    }

    public function isVisible(): bool
    {
        return $this !== self::SPECTATOR;
    }
}
