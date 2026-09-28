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

/** A block entity whose durable state is limited to its identity and position. */
final readonly class SimpleBlockEntity extends BlockEntity
{
    public function __construct(BlockEntityType $type, BlockPosition $position, int $revision = 0)
    {
        parent::__construct($type, $position, $revision);
        if ($type !== BlockEntityType::EnderChest) {
            throw new InvalidArgumentException('The requested block-entity type requires specialized durable state.');
        }
    }
}
