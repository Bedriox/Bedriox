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

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\ExperienceChangeCause;
use Bedriox\Api\Player\ExperienceSnapshot;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

final class PlayerExperienceChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ExperienceSnapshot $previous,
        private ExperienceSnapshot $experience,
        public readonly ExperienceChangeCause $cause,
    ) {}

    public function experience(): ExperienceSnapshot
    {
        return $this->experience;
    }
    public function setExperience(ExperienceSnapshot $experience): void
    {
        $this->assertMutable();
        $this->experience = $experience;
    }
    protected function state(): mixed
    {
        return [parent::state(), $this->experience];
    }
    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof ExperienceSnapshot) {
            throw new InvalidArgumentException('Invalid player experience event state.');
        }
        parent::replaceState($state[0]);
        $this->experience = $state[1];
    }
}
