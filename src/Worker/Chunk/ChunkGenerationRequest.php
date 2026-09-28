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

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generator\GeneratorIdentifier;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;

final readonly class ChunkGenerationRequest
{
    public function __construct(
        public string $generator,
        public int $generatorVersion,
        public int $seed,
        public string $dimension,
        public ChunkPosition $position,
        public GeneratorOptions $options = new GeneratorOptions(),
        public ?WorkerGeneratorSource $workerSource = null,
    ) {
        new GeneratorIdentifier($generator);
        if ($generatorVersion < 1 || $generatorVersion > 65_535
            || preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.-]{0,63}$/D', $dimension) !== 1) {
            throw new \InvalidArgumentException('Chunk generation request has unsupported generator metadata.');
        }
    }
}
