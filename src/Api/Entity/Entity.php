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

use Bedriox\Api\Entity\Controller\EntityController;
use Bedriox\Api\World\Position;

interface Entity
{
    public function getUniqueId(): string;

    public function getRuntimeId(): int;

    public function getType(): EntityType;

    public function getCategory(): EntityCategory;

    public function getPosition(): Position;

    public function getWorldName(): string;

    public function getYaw(): float;

    public function getPitch(): float;

    public function getCollisionWidth(): float;

    public function getCollisionHeight(): float;

    public function isOnGround(): bool;

    public function isPersistent(): bool;

    public function getVehicle(): ?Entity;

    public function isRiding(): bool;

    /** @return list<MountedPassenger> */
    public function getPassengers(): array;

    public function hasPassengers(): bool;

    public function getController(): EntityController;
}
