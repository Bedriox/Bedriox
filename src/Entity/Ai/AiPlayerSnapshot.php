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

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Immutable player projection available to AI without exposing mutable player state. */
final readonly class AiPlayerSnapshot
{
    public function __construct(
        public string $playerId,
        public string $worldName,
        public Position $position,
        public bool $damageable = true,
        public ?string $heldItemIdentifier = null,
        public bool $wearingGoldArmor = false,
        public float $headYaw = 0.0,
        public float $pitch = 0.0,
        public bool $wearingEndermanProtectiveHeadwear = false,
    ) {
        if ($playerId === '' || strlen($playerId) > 128 || preg_match('//u', $playerId) !== 1) {
            throw new InvalidArgumentException('AI player identity must be valid UTF-8 and bounded.');
        }
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new InvalidArgumentException('AI player world name must be valid UTF-8 and bounded.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('AI player position must be finite and bounded.');
        }
        if ($heldItemIdentifier !== null
            && (strlen($heldItemIdentifier) > 128
                || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $heldItemIdentifier) !== 1)) {
            throw new InvalidArgumentException('AI held-item identifier must be canonical and bounded.');
        }
        if (!is_finite($headYaw) || !is_finite($pitch) || abs($headYaw) > 360.0 || abs($pitch) > 90.0) {
            throw new InvalidArgumentException('AI player look rotation is invalid.');
        }
    }

    public function distanceSquaredTo(Position $position): float
    {
        $dx = $this->position->x - $position->x;
        $dy = $this->position->y - $position->y;
        $dz = $this->position->z - $position->z;

        return ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
    }
}
