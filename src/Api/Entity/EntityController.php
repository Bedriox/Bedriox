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

namespace Bedriox\Api\Entity;

use Bedriox\Api\World\Position;

/**
 * Bounded mutation gateway for one live authoritative entity.
 *
 * Controller calls made from a plugin callback are committed only when that
 * callback completes successfully. A controller becomes unavailable when its
 * entity leaves the world.
 */
interface EntityController
{
    public function isAvailable(): bool;

    public function teleport(Position $position, ?string $worldName = null): void;

    public function setRotation(float $yaw, float $pitch): void;

    public function setVelocity(float $x, float $y, float $z): void;

    public function setNameTag(string $nameTag): void;

    public function setNameTagVisible(bool $visible): void;

    public function setImmobile(bool $immobile): void;

    public function setInvisible(bool $invisible): void;

    public function setGlowing(bool $glowing): void;

    public function setScale(float $scale): void;

    public function setGravityEnabled(bool $enabled): void;

    public function setOnFire(
        int $durationTicks,
        EntityCombustionCause $cause = EntityCombustionCause::PLUGIN,
    ): void;

    public function extinguish(): void;

    public function despawn(): void;
}
