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

namespace Bedriox\Api\Event\Processing;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** @internal Shared bounded validation for public processing events. */
final class ProcessingEventValues
{
    public static function ticks(int $ticks, bool $zeroAllowed = false): int
    {
        if ($ticks < ($zeroAllowed ? 0 : 1) || $ticks > 1_728_000) {
            throw new InvalidArgumentException('Processing duration must be between ' . ($zeroAllowed ? '0' : '1') . ' and 1728000 ticks.');
        }
        return $ticks;
    }

    public static function experience(int $experience): int
    {
        if ($experience < 0 || $experience > 2_147_483_647) {
            throw new InvalidArgumentException('Experience value must be between 0 and 2147483647.');
        }
        return $experience;
    }

    public static function level(int $level, int $maximum = 15): int
    {
        if ($level < 0 || $level > $maximum) {
            throw new InvalidArgumentException("Level must be between 0 and {$maximum}.");
        }
        return $level;
    }

    public static function slot(int $slot, int $maximum): int
    {
        if ($slot < 0 || $slot > $maximum) {
            throw new InvalidArgumentException("Slot must be between 0 and {$maximum}.");
        }
        return $slot;
    }

    /**
     * @param array<mixed> $items
     * @return list<ItemStack>
     */
    public static function items(array $items, int $maximum, bool $emptyAllowed = false): array
    {
        if (!array_is_list($items) || (!$emptyAllowed && $items === []) || count($items) > $maximum) {
            throw new InvalidArgumentException('Processing item list is outside its supported bounds.');
        }
        $validated = [];
        foreach ($items as $item) {
            if (!$item instanceof ItemStack) {
                throw new InvalidArgumentException('Processing item list contains an invalid stack.');
            }
            $validated[] = $item;
        }
        return $validated;
    }

    public static function text(string $text, int $maximumBytes = 256): string
    {
        if (strlen($text) > $maximumBytes || preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException('Processing text must be valid UTF-8 within its byte limit.');
        }
        return $text;
    }
}
