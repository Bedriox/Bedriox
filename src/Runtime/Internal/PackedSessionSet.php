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

namespace Bedriox\Server\Runtime\Internal;

/** Compact mutable set for bounded runtime-session handles. */
final class PackedSessionSet
{
    private const int BITS_PER_BYTE = 8;

    private function __construct() {}

    public static function contains(string $members, int $handle): bool
    {
        $byte = intdiv($handle, self::BITS_PER_BYTE);

        return isset($members[$byte])
            && (ord($members[$byte]) & (1 << ($handle % self::BITS_PER_BYTE))) !== 0;
    }

    public static function add(string &$members, int $handle): void
    {
        $byte = intdiv($handle, self::BITS_PER_BYTE);
        $length = strlen($members);
        if ($length <= $byte) {
            $members .= str_repeat("\0", $byte + 1 - $length);
        }
        $members[$byte] = chr(ord($members[$byte]) | (1 << ($handle % self::BITS_PER_BYTE)));
    }

    public static function remove(string &$members, int $handle): void
    {
        $byte = intdiv($handle, self::BITS_PER_BYTE);
        if (!isset($members[$byte])) {
            return;
        }
        $members[$byte] = chr(ord($members[$byte]) & ~(1 << ($handle % self::BITS_PER_BYTE)));
        $members = rtrim($members, "\0");
    }

    /** @return list<int> */
    public static function handles(string $members): array
    {
        $handles = [];
        $length = strlen($members);
        for ($byte = 0; $byte < $length; ++$byte) {
            $value = ord($members[$byte]);
            if ($value === 0) {
                continue;
            }
            for ($bit = 0; $bit < self::BITS_PER_BYTE; ++$bit) {
                if (($value & (1 << $bit)) !== 0) {
                    $handles[] = ($byte * self::BITS_PER_BYTE) + $bit;
                }
            }
        }

        return $handles;
    }
}
