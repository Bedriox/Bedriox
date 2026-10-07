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

use Bedriox\Api\Entity\Capability\Animal;
use Bedriox\Api\Entity\Capability\Breedable;
use Bedriox\Api\Entity\Capability\Trusting;
use Bedriox\Api\Entity\Value\FoxVariant;

interface Fox extends Animal, Breedable, Trusting
{
    public function getVariant(): FoxVariant;

    public function isSleeping(): bool;

    public function isPouncing(): bool;

    public function isFaceplanted(): bool;

    /** @return list<string> */
    public function getTrustedPlayerUniqueIds(): array;
}
