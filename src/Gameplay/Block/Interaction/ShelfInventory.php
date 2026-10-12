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

namespace Bedriox\Server\Gameplay\Block\Interaction;

use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;
use OverflowException;

/** Immutable authoritative three-slot shelf contents. */
final readonly class ShelfInventory
{
    public const int SLOT_COUNT = 3;

    /** @var array<int, ContainerItemStack> */
    private array $contents;

    /** @param array<array-key, mixed> $contents */
    public function __construct(array $contents = [], public int $revision = 0)
    {
        if ($revision < 0) {
            throw new InvalidArgumentException('Shelf inventory revision cannot be negative.');
        }
        foreach ($contents as $slot => $stack) {
            if (!is_int($slot) || $slot < 0 || $slot >= self::SLOT_COUNT || !$stack instanceof ContainerItemStack) {
                throw new InvalidArgumentException('Shelf inventory contains an invalid slot entry.');
            }
        }
        ksort($contents, SORT_NUMERIC);
        $this->contents = $contents;
    }

    public function stackAt(int $slot): ?ContainerItemStack
    {
        self::assertSlot($slot);

        return $this->contents[$slot] ?? null;
    }

    /** @return array{ContainerItemStack|null, ContainerItemStack|null, ContainerItemStack|null} */
    public function slots(): array
    {
        return [$this->contents[0] ?? null, $this->contents[1] ?? null, $this->contents[2] ?? null];
    }

    public function exchange(int $slot, ?ContainerItemStack $heldStack): ShelfExchange
    {
        self::assertSlot($slot);
        $returned = $this->contents[$slot] ?? null;
        if ($returned == $heldStack) {
            return new ShelfExchange($this, $returned, $slot);
        }
        if ($this->revision === PHP_INT_MAX) {
            throw new OverflowException('Shelf inventory revision space is exhausted.');
        }
        $contents = $this->contents;
        if ($heldStack === null) {
            unset($contents[$slot]);
        } else {
            $contents[$slot] = $heldStack;
        }

        return new ShelfExchange(new self($contents, $this->revision + 1), $returned, $slot);
    }

    /** Selects the displayed shelf compartment from a normalized hit and canonical horizontal facing. */
    public static function slotFromHit(string $facing, float $hitX, float $hitZ): int
    {
        if (!is_finite($hitX) || !is_finite($hitZ) || $hitX < 0.0 || $hitX > 1.0 || $hitZ < 0.0 || $hitZ > 1.0) {
            throw new InvalidArgumentException('Shelf hit coordinates must be finite values between zero and one.');
        }
        $across = match ($facing) {
            'north' => 1.0 - $hitX,
            'south' => $hitX,
            'west' => $hitZ,
            'east' => 1.0 - $hitZ,
            default => throw new InvalidArgumentException('Shelf facing must be a horizontal cardinal direction.'),
        };

        return min(self::SLOT_COUNT - 1, (int) floor($across * self::SLOT_COUNT));
    }

    private static function assertSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= self::SLOT_COUNT) {
            throw new InvalidArgumentException('Shelf inventory slot is outside its supported range.');
        }
    }
}
