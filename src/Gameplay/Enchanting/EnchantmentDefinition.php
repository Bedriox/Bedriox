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

namespace Bedriox\Server\Gameplay\Enchanting;

use InvalidArgumentException;

final readonly class EnchantmentDefinition
{
    /** @var non-empty-list<EnchantmentItemCategory> */
    public array $categories;

    /** @var list<string> */
    public array $conflicts;

    /**
     * @param array<mixed> $categories
     * @param array<mixed> $conflicts
     */
    public function __construct(
        public string $identifier,
        public int $bedrockId,
        public int $maximumLevel,
        public int $weight,
        array $categories,
        public int $minimumCost,
        public int $levelCost,
        public int $costSpan,
        public bool $treasure = false,
        public bool $curse = false,
        public bool $discoverable = true,
        array $conflicts = [],
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
            || $bedrockId < 0 || $bedrockId > 32_767
            || $maximumLevel < 1 || $maximumLevel > 255
            || $weight < 1 || $weight > 100
            || $categories === []
            || $minimumCost < 1 || $minimumCost > 255
            || $levelCost < 0 || $levelCost > 255
            || $costSpan < 0 || $costSpan > 255) {
            throw new InvalidArgumentException('Enchantment definition is invalid.');
        }
        $this->categories = self::validateCategories($categories);
        $this->conflicts = self::validateConflicts($conflicts);
    }

    public function minimumCostForLevel(int $level): int
    {
        $this->validateLevel($level);
        return $this->minimumCost + (($level - 1) * $this->levelCost);
    }

    public function maximumCostForLevel(int $level): int
    {
        return $this->minimumCostForLevel($level) + $this->costSpan;
    }

    public function validateLevel(int $level): void
    {
        if ($level < 1 || $level > $this->maximumLevel) {
            throw new InvalidArgumentException('Enchantment level is outside its supported range.');
        }
    }

    public function conflictsWith(string $identifier): bool
    {
        return in_array($identifier, $this->conflicts, true);
    }

    /**
     * @param array<mixed> $categories
     * @return non-empty-list<EnchantmentItemCategory>
     */
    private static function validateCategories(array $categories): array
    {
        $validated = [];
        foreach ($categories as $category) {
            if (!$category instanceof EnchantmentItemCategory) {
                throw new InvalidArgumentException('Enchantment categories must be typed values.');
            }
            $validated[] = $category;
        }
        if ($validated === []) {
            throw new InvalidArgumentException('Enchantment categories cannot be empty.');
        }

        return $validated;
    }

    /**
     * @param array<mixed> $conflicts
     * @return list<string>
     */
    private static function validateConflicts(array $conflicts): array
    {
        $validated = [];
        foreach ($conflicts as $conflict) {
            if (!is_string($conflict) || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $conflict) !== 1) {
                throw new InvalidArgumentException('Enchantment conflicts must be canonical identifiers.');
            }
            $validated[] = $conflict;
        }

        return $validated;
    }
}
