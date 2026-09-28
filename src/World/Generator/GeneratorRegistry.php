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

use InvalidArgumentException;
use RuntimeException;

final class GeneratorRegistry
{
    public const int MAXIMUM_DEFINITIONS = 256;

    /** @var array<string, GeneratorDefinition> */
    private array $definitions = [];

    public function register(GeneratorDefinition $definition, bool $replace = false): void
    {
        $id = $definition->identifier->value;
        $existing = $this->definitions[$id] ?? null;
        if ($existing !== null && (!$replace || $existing->owner !== $definition->owner)) {
            throw new RuntimeException("World generator \"{$id}\" is already registered.");
        }
        if ($existing === null && count($this->definitions) >= self::MAXIMUM_DEFINITIONS) {
            throw new RuntimeException('World generator registry reached its definition limit.');
        }
        $this->definitions[$id] = $definition;
    }

    public function get(GeneratorIdentifier|string $identifier): ?GeneratorDefinition
    {
        $id = $identifier instanceof GeneratorIdentifier ? $identifier->value : (new GeneratorIdentifier($identifier))->value;

        return $this->definitions[$id] ?? null;
    }

    public function require(GeneratorIdentifier|string $identifier): GeneratorDefinition
    {
        return $this->get($identifier)
            ?? throw new InvalidArgumentException("World generator \"{$identifier}\" is not registered.");
    }

    public function create(GeneratorIdentifier|string $identifier, GeneratorContext $context): \Bedriox\Server\World\VersionedWorldGenerator
    {
        return $this->require($identifier)->create($context);
    }

    public function unregister(GeneratorIdentifier|string $identifier, string $owner): bool
    {
        $id = $identifier instanceof GeneratorIdentifier ? $identifier->value : (new GeneratorIdentifier($identifier))->value;
        $definition = $this->definitions[$id] ?? null;
        if ($definition === null) {
            return false;
        }
        if ($definition->owner !== $owner) {
            throw new RuntimeException("World generator \"{$id}\" belongs to another owner.");
        }
        unset($this->definitions[$id]);

        return true;
    }

    public function unregisterOwner(string $owner): int
    {
        $removed = 0;
        foreach ($this->definitions as $id => $definition) {
            if ($definition->owner === $owner) {
                unset($this->definitions[$id]);
                ++$removed;
            }
        }

        return $removed;
    }

    /** @return list<GeneratorDefinition> */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }
}
