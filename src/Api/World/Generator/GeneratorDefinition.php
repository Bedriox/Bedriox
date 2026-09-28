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

use InvalidArgumentException;
use ReflectionClass;

final readonly class GeneratorDefinition
{
    public function __construct(
        public string $identifier,
        public int $version,
        public string $generatorClass,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.-]{0,63}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Plugin generator identifier must be a bounded namespaced identifier.');
        }
        if ($version < 1 || $version > 65_535) {
            throw new InvalidArgumentException('Plugin generator version must be between 1 and 65535.');
        }
        if (!class_exists($generatorClass) || !is_a($generatorClass, Generator::class, true)) {
            throw new InvalidArgumentException('Plugin generator class must implement Generator.');
        }
        $reflection = new ReflectionClass($generatorClass);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() !== 0)) {
            throw new InvalidArgumentException('Plugin generator class must be constructible without arguments.');
        }
        if (!$reflection->isFinal() || $reflection->getProperties() !== []) {
            throw new InvalidArgumentException('Plugin generator class must be final and stateless.');
        }
    }
}
