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

namespace Bedriox\Api\Entity\Vanilla;

use Bedriox\Api\Entity\Animal;
use Bedriox\Api\Entity\Breedable;
use Bedriox\Api\Entity\Shearable;
use Bedriox\Api\Entity\SheepController;
use Bedriox\Api\Entity\WoolColor;

interface Sheep extends Animal, Breedable, Shearable
{
    public function getWoolColor(): WoolColor;

    public function getController(): SheepController;
}
