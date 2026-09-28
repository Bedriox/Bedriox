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

namespace Bedriox\Server\Entity\Persistence;

use InvalidArgumentException;

/** Exact durable snapshots committed by one atomic cross-chunk ownership move. */
final readonly class EntityOwnershipTransferResult
{
    public function __construct(
        public EntityChunkSnapshot $sourceAfter,
        public EntityChunkSnapshot $destinationAfter,
    ) {
        if ($sourceAfter->worldName !== $destinationAfter->worldName
            || ($sourceAfter->chunk->x === $destinationAfter->chunk->x
                && $sourceAfter->chunk->z === $destinationAfter->chunk->z)) {
            throw new InvalidArgumentException('Entity ownership transfer result must contain distinct chunks in one world.');
        }
    }
}
