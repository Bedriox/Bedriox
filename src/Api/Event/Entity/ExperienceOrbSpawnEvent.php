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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

final class ExperienceOrbSpawnEvent extends CancellableEvent
{
    public function __construct(public readonly Position $position, private int $value)
    {
        $this->setValue($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function setValue(int $value): void
    {
        $this->assertMutable();
        if ($value < 1 || $value > 32_767) {
            throw new InvalidArgumentException('Experience orb value must be between 1 and 32767.');
        }
        $this->value = $value;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->value];
    }
    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid experience-orb event state.');
        }
        parent::replaceState($state[0]);
        $this->value = $state[1];
    }
}
