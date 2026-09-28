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

namespace Bedriox\Server\World\Generator;

use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\VersionedWorldGenerator;

/** Applies registered identity metadata without duplicating generator state. */
final readonly class DefinedWorldGenerator implements VersionedWorldGenerator
{
    public function __construct(
        private string $identifier,
        private int $definitionVersion,
        private VersionedWorldGenerator $inner,
    ) {}

    public function name(): string
    {
        return $this->identifier;
    }

    public function version(): int
    {
        return $this->definitionVersion;
    }

    public function generate(ChunkPosition $position): Chunk
    {
        return $this->inner->generate($position);
    }

    public function defaultSpawn(): SpawnPosition
    {
        return $this->inner->defaultSpawn();
    }
}
