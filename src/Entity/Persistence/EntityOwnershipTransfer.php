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

use Bedriox\Server\Entity\EntityUuid;
use InvalidArgumentException;

/** Immutable after-snapshots for one atomic cross-chunk entity move. */
final readonly class EntityOwnershipTransfer
{
    public function __construct(
        public string $uuid,
        public int $expectedEntityRevision,
        public EntityChunkSnapshot $sourceAfter,
        public EntityChunkSnapshot $destinationAfter,
    ) {
        if (EntityUuid::validate($uuid) !== $uuid || $expectedEntityRevision < 0) {
            throw new InvalidArgumentException('Entity ownership transfer identity is invalid.');
        }
        if ($sourceAfter->worldName !== $destinationAfter->worldName
            || ($sourceAfter->chunk->x === $destinationAfter->chunk->x
                && $sourceAfter->chunk->z === $destinationAfter->chunk->z)) {
            throw new InvalidArgumentException('Entity ownership transfer must cross chunks in one world.');
        }
        if (isset($sourceAfter->revisions()[$uuid])) {
            throw new InvalidArgumentException('Entity ownership transfer source must no longer contain the entity.');
        }
        $destinationRevision = $destinationAfter->revisions()[$uuid] ?? null;
        if (!is_int($destinationRevision) || $destinationRevision <= $expectedEntityRevision) {
            throw new InvalidArgumentException('Entity ownership transfer destination must contain an advanced revision.');
        }
    }
}
