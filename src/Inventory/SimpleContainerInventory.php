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

namespace Bedriox\Server\Inventory;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Fixed-size bounded storage with optimistic revision checks and persistence dirty tracking. */
final class SimpleContainerInventory extends AbstractContainerInventory
{
    /** @var list<ItemStack|null> */
    private array $slots;

    /** @param array<mixed>|null $contents */
    public function __construct(string $identifier, private readonly int $slotCount, ?array $contents = null)
    {
        if ($slotCount < 1 || $slotCount > self::MAX_SLOTS) {
            throw new InvalidArgumentException('Container inventory size is outside its supported range.');
        }
        parent::__construct($identifier);
        $contents ??= array_fill(0, $slotCount, null);
        $this->slots = self::validatedContents($contents, $slotCount);
    }

    public function size(): int
    {
        return $this->slotCount;
    }

    public function stackAt(int $slot): ?ItemStack
    {
        self::validateSlot($slot, $this->slotCount);

        return $this->slots[$slot];
    }

    public function contents(): array
    {
        return $this->slots;
    }

    public function setStack(int $slot, ?ItemStack $stack, ?string $expectedRevision = null): bool
    {
        self::validateSlot($slot, $this->slotCount);
        $this->assertRevision($expectedRevision);
        if (self::sameStack($this->slots[$slot], $stack)) {
            return false;
        }
        $updated = [];
        foreach ($this->slots as $index => $current) {
            $updated[] = $index === $slot ? $stack : $current;
        }
        $this->slots = $updated;
        $this->recordMutation();

        return true;
    }

    public function replaceContents(array $contents, ?string $expectedRevision = null): bool
    {
        $this->assertRevision($expectedRevision);
        $contents = self::validatedContents($contents, $this->slotCount);
        foreach ($contents as $slot => $stack) {
            if (!self::sameStack($this->slots[$slot], $stack)) {
                $this->slots = $contents;
                $this->recordMutation();

                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $contents
     * @return list<ItemStack|null>
     */
    private static function validatedContents(array $contents, int $size): array
    {
        if (!array_is_list($contents) || count($contents) !== $size) {
            throw new InvalidArgumentException('Container contents must contain exactly one entry per slot.');
        }
        $normalized = [];
        foreach ($contents as $stack) {
            if ($stack !== null && !$stack instanceof ItemStack) {
                throw new InvalidArgumentException('Container contents may only contain inventory stacks or null.');
            }
            $normalized[] = $stack;
        }

        return $normalized;
    }

    private static function sameStack(?ItemStack $left, ?ItemStack $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null
                && $left->identifier === $right->identifier
                && $left->count === $right->count
                && $left->damage === $right->damage
                && ($left->nbt?->toBinary() ?? '') === ($right->nbt?->toBinary() ?? '')
                && $left->auxValue === $right->auxValue);
    }
}
