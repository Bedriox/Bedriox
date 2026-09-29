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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Server\Gameplay\Enchanting\VanillaEnchantmentIdMap;
use InvalidArgumentException;

/** Bounded semantic item metadata used by processing services before wire projection. */
final class WorkstationItemData
{
    private const string ENCHANTMENTS_TAG = 'ench';
    private const string ENCHANTMENT_ID_TAG = 'id';
    private const string ENCHANTMENT_LEVEL_TAG = 'lvl';
    private const string DISPLAY_TAG = 'display';
    private const string DISPLAY_NAME_TAG = 'Name';
    private const string REPAIR_COST_TAG = 'RepairCost';

    /** @return array<string, int> */
    public static function enchantments(?ItemNbt $nbt): array
    {
        $tag = $nbt?->tag(self::ENCHANTMENTS_TAG);
        if ($tag === null || $tag->type() !== TagType::LIST) {
            return [];
        }
        $values = $tag->value();
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $enchantmentTag) {
            if (!$enchantmentTag instanceof Tag || $enchantmentTag->type() !== TagType::COMPOUND) {
                continue;
            }
            $enchantment = $enchantmentTag->value();
            if (!is_array($enchantment)) {
                continue;
            }
            $idTag = $enchantment[self::ENCHANTMENT_ID_TAG] ?? null;
            $levelTag = $enchantment[self::ENCHANTMENT_LEVEL_TAG] ?? null;
            $id = $idTag instanceof Tag && $idTag->type() === TagType::SHORT ? $idTag->value() : null;
            $level = $levelTag instanceof Tag && $levelTag->type() === TagType::SHORT ? $levelTag->value() : null;
            $identifier = is_int($id) ? VanillaEnchantmentIdMap::identifier($id) : null;
            if ($identifier !== null && is_int($level) && $level >= 1 && $level <= 255) {
                $result[$identifier] = $level;
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @param array<string, int> $enchantments */
    public static function withEnchantments(?ItemNbt $nbt, array $enchantments): ItemNbt
    {
        if (count($enchantments) > 32) {
            throw new InvalidArgumentException('An item cannot carry more than 32 processing enchantments.');
        }
        $validated = [];
        foreach ($enchantments as $identifier => $level) {
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
                || $level < 1 || $level > 255) {
                throw new InvalidArgumentException('Item enchantment is invalid.');
            }
            $id = VanillaEnchantmentIdMap::id($identifier);
            if ($id === null) {
                throw new InvalidArgumentException('Item enchantment is not registered with Bedrock.');
            }
            $validated[$identifier] = [$id, $level];
        }
        ksort($validated, SORT_STRING);
        $tags = [];
        foreach ($validated as [$id, $level]) {
            $tags[] = Tag::compound([
                self::ENCHANTMENT_ID_TAG => Tag::short($id),
                self::ENCHANTMENT_LEVEL_TAG => Tag::short($level),
            ]);
        }
        return ($nbt ?? ItemNbt::empty())->withTag(
            self::ENCHANTMENTS_TAG,
            Tag::list(TagType::COMPOUND, $tags),
        );
    }

    public static function withoutEnchantments(?ItemNbt $nbt): ?ItemNbt
    {
        if ($nbt === null) {
            return null;
        }
        $updated = $nbt->withoutTag(self::ENCHANTMENTS_TAG);
        return $updated->isEmpty() ? null : $updated;
    }

    public static function withDisplayName(?ItemNbt $nbt, ?string $name): ?ItemNbt
    {
        if ($name !== null && (preg_match('//u', $name) !== 1 || mb_strlen($name) > 50)) {
            throw new InvalidArgumentException('Anvil name must be valid UTF-8 and at most 50 characters.');
        }
        $updated = $nbt ?? ItemNbt::empty();
        $display = $updated->tag(self::DISPLAY_TAG);
        $values = [];
        $existing = $display?->value();
        if ($display?->type() === TagType::COMPOUND && is_array($existing)) {
            foreach ($existing as $key => $value) {
                if (is_string($key) && $value instanceof Tag) {
                    $values[$key] = $value;
                }
            }
        }
        if ($name === null || $name === '') {
            unset($values[self::DISPLAY_NAME_TAG]);
        } else {
            $values[self::DISPLAY_NAME_TAG] = Tag::string($name);
        }
        $updated = $values === []
            ? $updated->withoutTag(self::DISPLAY_TAG)
            : $updated->withTag(self::DISPLAY_TAG, Tag::compound($values));
        return $updated->isEmpty() ? null : $updated;
    }

    public static function displayName(?ItemNbt $nbt): ?string
    {
        $display = $nbt?->tag(self::DISPLAY_TAG);
        $values = $display?->value();
        if ($display?->type() !== TagType::COMPOUND || !is_array($values)) {
            return null;
        }
        $name = $values[self::DISPLAY_NAME_TAG] ?? null;
        return $name instanceof Tag && $name->type() === TagType::STRING && is_string($name->value())
            ? $name->value()
            : null;
    }

    public static function repairCost(?ItemNbt $nbt): int
    {
        return max(0, min(32_767, $nbt?->int(self::REPAIR_COST_TAG) ?? 0));
    }

    public static function withRepairCost(?ItemNbt $nbt, int $cost): ItemNbt
    {
        if ($cost < 0 || $cost > 32_767) {
            throw new InvalidArgumentException('Item repair cost is outside its supported range.');
        }
        return ($nbt ?? ItemNbt::empty())->withTag(self::REPAIR_COST_TAG, Tag::int($cost));
    }

    private function __construct() {}
}
