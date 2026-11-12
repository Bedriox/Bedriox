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

namespace Bedriox\Api\Map;

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\DimensionType;

interface MapView
{
    public function getId(): int;

    public function getDimensionType(): DimensionType;

    public function getScale(): MapScale;

    public function isLocked(): bool;

    public function setLocked(bool $locked): void;

    public function createItem(): ItemStack;

}
