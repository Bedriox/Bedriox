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

/** Generator and spawn overrides for one dimension of a newly created named world. */
final readonly class DimensionCreationOptions
{
    /**
     * @param array<string|int, array<array-key, mixed>|bool|float|int|string|null> $generatorOptions
     */
    public function __construct(
        public string $generator,
        public ?int $seed = null,
        public array $generatorOptions = [],
        public ?Position $spawn = null,
    ) {
        WorldCreationOptions::validateGeneratorIdentifier($generator);
        WorldCreationOptions::validateBoundedGeneratorOptions($generatorOptions);
        if ($spawn !== null) {
            if ($spawn->world !== null) {
                throw new InvalidArgumentException('A new dimension spawn may not reference an existing world.');
            }
            $spawn->validate();
        }
    }
}
