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

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

/** Immutable, protocol-independent view of one authoritative inventory revision. */
final readonly class InventoryView
{
    /** @var list<ItemStack|null> */
    public array $slots;

    /**
     * @param non-empty-string $identifier
     * @param array<mixed> $slots
     * @param string $revision Opaque revision token; compare it for equality only.
     */
    public function __construct(
        public string $identifier,
        array $slots,
        public string $revision,
    ) {
        if (strlen($identifier) > 256 || preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Inventory identifier must be non-empty, bounded, and portable.');
        }
        if ($slots === [] || count($slots) > 256 || !array_is_list($slots)) {
            throw new InvalidArgumentException('Inventory slots must be a non-empty bounded list.');
        }
        if ($revision === '' || strlen($revision) > 128) {
            throw new InvalidArgumentException('Inventory revision must be a non-empty bounded token.');
        }
        $normalized = [];
        foreach ($slots as $stack) {
            if ($stack !== null && !$stack instanceof ItemStack) {
                throw new InvalidArgumentException('Inventory slots may only contain item stacks or null.');
            }
            $normalized[] = $stack;
        }
        $this->slots = $normalized;
    }

    public function size(): int
    {
        return count($this->slots);
    }

    public function stackAt(int $slot): ?ItemStack
    {
        if ($slot < 0 || $slot >= count($this->slots)) {
            throw new InvalidArgumentException('Inventory slot is outside the inventory.');
        }

        return $this->slots[$slot];
    }
}
