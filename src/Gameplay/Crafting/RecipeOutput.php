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

namespace Bedriox\Server\Gameplay\Crafting;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Canonical recipe output before a play-session stack network ID is assigned. */
final readonly class RecipeOutput
{
    public function __construct(
        public string $identifier,
        public int $count = 1,
        public int $damage = 0,
        public ?ItemNbt $nbt = null,
        public int $auxValue = 0,
        public ?InternalBlockStateId $placedBlockState = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Recipe output identifier must be canonical and namespaced.');
        }
        if ($count < 1 || $count > 64) {
            throw new InvalidArgumentException('Recipe output count must be between 1 and 64.');
        }
        if ($damage < 0 || $damage > 65_535) {
            throw new InvalidArgumentException('Recipe output damage is outside its supported range.');
        }
        if ($auxValue < 0 || $auxValue > 32_767) {
            throw new InvalidArgumentException('Recipe output auxiliary value is outside its supported range.');
        }
    }

    public function toInventoryStack(int $stackNetworkId, int $count = 0): InventoryStack
    {
        return new InventoryStack(
            $this->identifier,
            $count === 0 ? $this->count : $count,
            $stackNetworkId,
            $this->placedBlockState,
            $this->damage,
            nbt: $this->nbt,
            auxValue: $this->auxValue,
        );
    }
}
