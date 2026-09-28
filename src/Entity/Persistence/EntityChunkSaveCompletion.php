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

use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

/** Immutable acknowledgement for one asynchronously persisted entity-owner chunk. */
final readonly class EntityChunkSaveCompletion
{
    public function __construct(
        public ChunkPosition $chunk,
        public int $revision,
        public bool $successful,
        public ?string $failureCode = null,
        public ?string $failureDetail = null,
    ) {
        if ($revision < 1) {
            throw new InvalidArgumentException('Entity chunk save completion revision must be positive.');
        }
        if ($successful && ($failureCode !== null || $failureDetail !== null)) {
            throw new InvalidArgumentException('Successful entity chunk save completion cannot contain a failure.');
        }
        if (!$successful && ($failureCode === null || $failureCode === '')) {
            throw new InvalidArgumentException('Failed entity chunk save completion requires a failure code.');
        }
    }
}
