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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Durable metadata which cannot be represented by the canonical cauldron block state. */
final readonly class CauldronBlockEntity extends BlockEntity
{
    public function __construct(BlockPosition $position, public ?int $potionAuxValue = null, int $revision = 0)
    {
        parent::__construct(BlockEntityType::Cauldron, $position, $revision);
        if ($potionAuxValue !== null && ($potionAuxValue < 0 || $potionAuxValue > 32_767)) {
            throw new InvalidArgumentException('Cauldron potion metadata is outside its supported range.');
        }
    }
}
