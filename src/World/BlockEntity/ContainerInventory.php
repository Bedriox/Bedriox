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

namespace Bedriox\Server\World\BlockEntity;

use InvalidArgumentException;

/** Immutable fixed-size storage. A caller stages a replacement before installing it into its owning chunk. */
final readonly class ContainerInventory
{
    public const int MAXIMUM_SLOTS = 54;

    /** @var array<int, ContainerItemStack> */
    private array $contents;

    /** @param array<array-key, mixed> $contents */
    public function __construct(
        public int $size,
        array $contents = [],
        public int $revision = 0,
    ) {
        if ($size < 1 || $size > self::MAXIMUM_SLOTS) {
            throw new InvalidArgumentException('Container inventory size is outside its supported range.');
        }
        if ($revision < 0) {
            throw new InvalidArgumentException('Container inventory revision cannot be negative.');
        }
        foreach ($contents as $slot => $stack) {
            if (!is_int($slot) || $slot < 0 || $slot >= $size || !$stack instanceof ContainerItemStack) {
                throw new InvalidArgumentException('Container inventory contains an invalid slot entry.');
            }
        }
        ksort($contents, SORT_NUMERIC);
        $this->contents = $contents;
    }

    public static function empty(int $size): self
    {
        return new self($size);
    }

    public function stackAt(int $slot): ?ContainerItemStack
    {
        $this->assertSlot($slot);

        return $this->contents[$slot] ?? null;
    }

    /** @return list<ContainerItemStack|null> */
    public function slots(): array
    {
        $slots = array_fill(0, $this->size, null);
        foreach ($this->contents as $slot => $stack) {
            $slots[$slot] = $stack;
        }

        return array_values($slots);
    }

    /** @return array<int, ContainerItemStack> */
    public function contents(): array
    {
        return $this->contents;
    }

    public function withStack(int $slot, ?ContainerItemStack $stack): self
    {
        $this->assertSlot($slot);
        $contents = $this->contents;
        $existing = $contents[$slot] ?? null;
        if ($existing == $stack) {
            return $this;
        }
        if ($stack === null) {
            unset($contents[$slot]);
        } else {
            $contents[$slot] = $stack;
        }

        return new self($this->size, $contents, self::nextRevision($this->revision));
    }

    /** @param array<int, ContainerItemStack> $contents */
    public function withContents(array $contents): self
    {
        $replacement = new self($this->size, $contents, self::nextRevision($this->revision));

        return $replacement->contents === $this->contents ? $this : $replacement;
    }

    private function assertSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= $this->size) {
            throw new InvalidArgumentException('Container inventory slot is outside its supported range.');
        }
    }

    private static function nextRevision(int $revision): int
    {
        if ($revision === PHP_INT_MAX) {
            throw new \OverflowException('Container inventory revision space is exhausted.');
        }

        return $revision + 1;
    }
}
