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

namespace Bedriox\Api\Event\Encounter;

use Bedriox\Api\Encounter\EnderDragonEncounter;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

final class EnderDragonRewardEvent extends CancellableEvent
{
    public function __construct(public readonly EnderDragonEncounter $encounter, public readonly bool $firstVictory, private int $experience)
    {
        self::validate($experience);
    }

    public function experience(): int
    {
        return $this->experience;
    }

    public function setExperience(int $experience): void
    {
        $this->assertMutable();
        self::validate($experience);
        $this->experience = $experience;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->experience];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid dragon reward event state.');
        }
        parent::replaceState($state[0]);
        self::validate($state[1]);
        $this->experience = $state[1];
    }

    private static function validate(int $experience): void
    {
        if ($experience < 0 || $experience > 1_000_000) {
            throw new InvalidArgumentException('Dragon reward experience is outside its supported bounds.');
        }
    }
}
