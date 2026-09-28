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

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generator\BuiltInGeneratorDefinitions;
use Bedriox\Server\World\Generator\DefinedWorldGenerator;
use Bedriox\Server\World\Generator\GeneratorContext;
use Bedriox\Server\World\Generator\GeneratorIdentifier;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\GeneratorRegistry;

final class WorldGeneratorFactory
{
    private function __construct() {}

    public static function create(
        string $name,
        int $seed,
        BlockStateRegistry $states,
        ?GeneratorOptions $options = null,
        string $dimension = 'minecraft:overworld',
        ?GeneratorRegistry $registry = null,
    ): VersionedWorldGenerator {
        $registry ??= self::builtIns();

        $generator = $registry->create(
            self::canonicalIdentifier($name),
            new GeneratorContext($seed, $dimension, $options ?? new GeneratorOptions(), $states),
        );
        if (in_array($name, [
            WorldGeneratorType::Default->value,
            WorldGeneratorType::Flat->value,
            'void',
        ], true)) {
            return new DefinedWorldGenerator($name, $generator->version(), $generator);
        }

        return $generator;
    }

    public static function canonicalIdentifier(string $name): string
    {
        return match ($name) {
            WorldGeneratorType::Default->value => BuiltInGeneratorDefinitions::DEFAULT,
            WorldGeneratorType::Flat->value => BuiltInGeneratorDefinitions::FLAT,
            'void' => BuiltInGeneratorDefinitions::VOID,
            default => (new GeneratorIdentifier($name))->value,
        };
    }

    public static function builtIns(): GeneratorRegistry
    {
        $registry = new GeneratorRegistry();
        BuiltInGeneratorDefinitions::register($registry);

        return $registry;
    }
}
