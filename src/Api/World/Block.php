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

final readonly class Block
{
    public BlockPosition $position;

    public string $identifier;

    /** @var array<string, bool|int|string> */
    public array $properties;

    /** @param array<mixed, mixed> $properties */
    public function __construct(
        BlockPosition $position,
        string $identifier,
        array $properties = [],
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Block identifier must be canonical and namespaced.');
        }
        $normalizedProperties = [];
        foreach ($properties as $name => $value) {
            if (!is_string($name) || preg_match('/^[a-z0-9_.:-]+$/D', $name) !== 1) {
                throw new InvalidArgumentException('Block property names must be canonical strings.');
            }
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidArgumentException('Block property values must be booleans, integers, or strings.');
            }
            $normalizedProperties[$name] = $value;
        }
        ksort($normalizedProperties);
        $this->position = $position;
        $this->identifier = $identifier;
        $this->properties = $normalizedProperties;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function is(string|BlockTag $identity): bool
    {
        return $identity instanceof BlockTag
            ? BlockTagRegistry::contains($identity, $this->identifier)
            : $this->identifier === $identity;
    }

    public function hasTag(BlockTag $tag): bool
    {
        return BlockTagRegistry::contains($tag, $this->identifier);
    }

    /** @return list<BlockTag> */
    public function getTags(): array
    {
        return BlockTagRegistry::tagsFor($this->identifier);
    }
}
