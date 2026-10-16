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

namespace Bedriox\Api\Entity\Value;

enum VillagerLevel: int
{
    public const int MAXIMUM_EXPERIENCE = 10_000;

    case NOVICE = 1;
    case APPRENTICE = 2;
    case JOURNEYMAN = 3;
    case EXPERT = 4;
    case MASTER = 5;

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    public static function forExperience(int $experience): self
    {
        if ($experience < 0 || $experience > self::MAXIMUM_EXPERIENCE) {
            throw new \InvalidArgumentException('Villager experience is outside its supported range.');
        }

        return match (true) {
            $experience >= 250 => self::MASTER,
            $experience >= 150 => self::EXPERT,
            $experience >= 70 => self::JOURNEYMAN,
            $experience >= 10 => self::APPRENTICE,
            default => self::NOVICE,
        };
    }
}
