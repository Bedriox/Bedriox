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

namespace Bedriox\Api\World\Generator;

/**
 * A deterministic, side-effect-free world generator.
 *
 * Implementations must depend only on the supplied context and chunk position.
 * They must not access players, live worlds, sockets, global mutable state, files,
 * clocks, or random sources not derived from the supplied seed.
 */
interface Generator
{
    public function generate(GeneratorContext $context, ChunkPosition $position): GeneratedChunk;

    public function defaultSpawn(GeneratorContext $context): GeneratorSpawn;
}
