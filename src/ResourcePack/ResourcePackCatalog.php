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

namespace Bedriox\Server\ResourcePack;

use InvalidArgumentException;

final readonly class ResourcePackCatalog
{
    /** @var list<ResourcePackArchive> */
    public array $stack;

    /** @var array<string, ResourcePackArchive> */
    private array $byId;

    /** @param array<array-key, mixed> $stack */
    public function __construct(array $stack = [], public bool $required = false)
    {
        if (!array_is_list($stack) || count($stack) > 32) {
            throw new InvalidArgumentException('Resource-pack stack exceeds 32 packs.');
        }
        $validated = [];
        $byId = [];
        foreach ($stack as $pack) {
            if (!$pack instanceof ResourcePackArchive) {
                throw new InvalidArgumentException('Resource-pack stack contains an invalid entry.');
            }
            $id = strtolower($pack->id);
            if (isset($byId[$id])) {
                throw new InvalidArgumentException("Resource-pack UUID {$pack->id} is duplicated.");
            }
            $byId[$id] = $pack;
            $validated[] = $pack;
        }
        $this->stack = $validated;
        $this->byId = $byId;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function count(): int
    {
        return count($this->stack);
    }

    public function isEmpty(): bool
    {
        return $this->stack === [];
    }

    public function pack(string $id): ?ResourcePackArchive
    {
        $separator = strpos($id, '_');
        $uuid = strtolower($separator === false ? $id : substr($id, 0, $separator));
        $pack = $this->byId[$uuid] ?? null;
        if ($pack === null || ($separator !== false && $pack->wireId() !== strtolower($id))) {
            return null;
        }

        return $pack;
    }
}
