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

interface MobController extends LivingEntityController
{
    public function setAiEnabled(bool $enabled): void;

    public function moveToward(Position $target, float $speed): void;

    public function moveAway(Position $target, float $speed): void;

    public function stopMoving(): void;

    public function lookAt(Position $target): void;

    public function target(Entity $target, float $speed): void;

    public function clearTarget(): void;
}
