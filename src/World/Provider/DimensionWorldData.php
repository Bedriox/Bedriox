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

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\SpawnPosition;
use InvalidArgumentException;

/** Persisted generator contract for one dimension of a named Bedrock world. */
final readonly class DimensionWorldData
{
    public function __construct(
        public string $generatorName,
        public int $seed,
        public SpawnPosition $spawn,
        public int $generatorVersion = 1,
        public string $generatorOptions = '{}',
        public bool $bedrioxGeneratorVersionDeclared = true,
    ) {
        if ($generatorName === '' || strlen($generatorName) > 128
            || preg_match('/^[A-Za-z0-9._-]+(?::[A-Za-z0-9._\/-]+)?$/D', $generatorName) !== 1) {
            throw new InvalidArgumentException('Dimension generator name must be a bounded canonical identifier.');
        }
        if ($generatorVersion < 1 || $generatorVersion > 2_147_483_647) {
            throw new InvalidArgumentException('Dimension generator version must be a positive bounded integer.');
        }
        GeneratorOptions::fromJson($generatorOptions);
    }
}
