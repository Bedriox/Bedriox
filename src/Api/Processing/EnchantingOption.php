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

namespace Bedriox\Api\Processing;

use InvalidArgumentException;

final readonly class EnchantingOption
{
    /** @var array<string, int> */
    public array $enchantments;

    /** @param array<mixed> $enchantments */
    public function __construct(public int $slot, public int $requiredLevel, public int $lapisCost, public int $enchantmentSeed, array $enchantments)
    {
        if ($slot < 0 || $slot > 2 || $requiredLevel < 0 || $requiredLevel > 32_767 || $lapisCost < 0 || $lapisCost > 64 || $enchantmentSeed < 0) {
            throw new InvalidArgumentException('Enchanting option values are outside their supported bounds.');
        }
        if ($enchantments === [] || count($enchantments) > 32) {
            throw new InvalidArgumentException('Enchanting options must contain between one and 32 enchantments.');
        }
        $validated = [];
        foreach ($enchantments as $identifier => $level) {
            if (!is_string($identifier) || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1 || !is_int($level) || $level < 1 || $level > 255) {
                throw new InvalidArgumentException('Enchanting option contains an invalid enchantment.');
            }
            $validated[$identifier] = $level;
        }
        $this->enchantments = $validated;
    }
}
