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

namespace Bedriox\Api\World;

use InvalidArgumentException;

/**
 * Lightweight identity for one loaded generation of a world.
 *
 * The server reuses one handle instance for a loaded world. This value never
 * owns chunks, entities, storage, generators, or other runtime state, so a
 * retained plugin reference cannot keep an unloaded world alive.
 */
final readonly class World
{
    public function __construct(
        private string $id,
        private int $loadGeneration,
        private ?WorldActions $actions = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1 || $id === '.' || $id === '..') {
            throw new InvalidArgumentException('World ID must be a canonical lowercase identifier of 1-64 characters.');
        }
        if ($loadGeneration < 1) {
            throw new InvalidArgumentException('World load generation must be positive.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function loadGeneration(): int
    {
        return $this->loadGeneration;
    }

    /** True only for the same logical world and the same loaded runtime generation. */
    public function isSameLoad(self $other): bool
    {
        return $this->id === $other->id && $this->loadGeneration === $other->loadGeneration;
    }

    /** True for the same logical world even if the other handle belongs to an older load. */
    public function isSameWorld(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function getBlock(BlockPosition $position): Block
    {
        return ($this->actions ?? WorldActions::unavailable())->getBlock($this, $position);
    }

    public function setBlock(BlockPosition $position, string $identifier): void
    {
        ($this->actions ?? WorldActions::unavailable())->setBlock($this, $position, $identifier);
    }
}
