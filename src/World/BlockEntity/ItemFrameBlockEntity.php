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

/** Persistent item-frame contents and presentation state. */
final readonly class ItemFrameBlockEntity extends BlockEntity
{
    public function __construct(
        BlockPosition $position,
        public ?ContainerItemStack $item = null,
        public int $rotation = 0,
        public float $dropChance = 1.0,
        public bool $fixed = false,
        bool $glowing = false,
        int $revision = 0,
    ) {
        if ($rotation < 0 || $rotation > 7 || !is_finite($dropChance) || $dropChance < 0.0 || $dropChance > 1.0) {
            throw new \InvalidArgumentException('Item-frame presentation state is invalid.');
        }
        parent::__construct($glowing ? BlockEntityType::GlowItemFrame : BlockEntityType::ItemFrame, $position, $revision);
    }

    public function isGlowing(): bool
    {
        return $this->type === BlockEntityType::GlowItemFrame;
    }
}
