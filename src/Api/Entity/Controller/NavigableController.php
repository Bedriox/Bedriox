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

namespace Bedriox\Api\Entity\Controller;

use Bedriox\Api\World\Position;

/** Optional steering and obstacle-aware navigation shared by mobs and humans. */
interface NavigableController extends EntityController
{
    public function moveToward(Position $target, float $speed): void;

    public function navigateTo(Position $target, float $speed): void;

    public function cancelNavigation(): void;

    public function isNavigating(): bool;

    public function getNavigationTarget(): ?Position;

    public function stopMoving(): void;

    public function lookAt(Position $target): void;

    public function jump(): void;
}
