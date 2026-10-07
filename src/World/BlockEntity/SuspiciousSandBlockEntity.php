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

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Immutable hidden loot and brushing state owned by one generated suspicious-sand block. */
final readonly class SuspiciousSandBlockEntity extends BlockEntity
{
    public const int MAXIMUM_PROGRESS = 3;
    public const string WARM_OCEAN_RUIN_PROVENANCE = 'bedriox:warm_ocean_ruin';

    public function __construct(
        BlockPosition $position,
        public ContainerItemStack $hiddenItem,
        public int $lootSeed,
        public string $provenance,
        public int $progress = 0,
        int $revision = 0,
    ) {
        if ($hiddenItem->count !== 1) {
            throw new InvalidArgumentException('Suspicious-sand hidden loot must contain exactly one item.');
        }
        if ($provenance !== self::WARM_OCEAN_RUIN_PROVENANCE) {
            throw new InvalidArgumentException('Suspicious-sand provenance is not an admitted generated structure.');
        }
        if ($progress < 0 || $progress > self::MAXIMUM_PROGRESS) {
            throw new InvalidArgumentException('Suspicious-sand brush progress is outside its bounded range.');
        }
        parent::__construct(BlockEntityType::BrushableBlock, $position, $revision);
    }

    public static function empty(BlockPosition $position): self
    {
        return new self(
            $position,
            new ContainerItemStack('minecraft:brick', 1),
            0,
            self::WARM_OCEAN_RUIN_PROVENANCE,
        );
    }

    public function withProgress(int $progress): self
    {
        if ($progress === $this->progress) {
            return $this;
        }

        return new self(
            $this->position,
            $this->hiddenItem,
            $this->lootSeed,
            $this->provenance,
            $progress,
            $this->revision + 1,
        );
    }
}
