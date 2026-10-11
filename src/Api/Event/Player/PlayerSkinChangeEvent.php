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

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Entity\Skin;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable intent emitted only for a connected real player's skin change. */
final class PlayerSkinChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Skin $previous,
        private Skin $skin,
    ) {}

    public function skin(): Skin
    {
        return $this->skin;
    }

    public function setSkin(Skin $skin): void
    {
        $this->assertMutable();
        $this->skin = $skin;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->skin];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof Skin) {
            throw new InvalidArgumentException('Invalid player skin event state.');
        }
        parent::replaceState($state[0]);
        $this->skin = $state[1];
    }
}
