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

use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentApplicability;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentDefinition;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentRegistry;
use Bedriox\Server\Gameplay\Enchanting\VanillaEnchantmentRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;

/** Deterministically generates and applies the three bounded enchanting-table offers. */
final readonly class EnchantingProcessor
{
    private const int MAXIMUM_BOOKSHELVES = 15;

    private EnchantmentRegistry $enchantments;

    public function __construct(
        private ?ItemCatalog $items = null,
        ?EnchantmentRegistry $enchantments = null,
    ) {
        $this->enchantments = $enchantments ?? VanillaEnchantmentRegistry::create();
    }

    /** @return list<EnchantingOption> */
    public function options(ContainerItemStack $item, int $bookshelves, int $seed): array
    {
        if ($bookshelves < 0 || $bookshelves > self::MAXIMUM_BOOKSHELVES || $seed < 0) {
            throw new InvalidArgumentException('Enchanting context is outside its supported bounds.');
        }
        if (WorkstationItemData::enchantments($item->nbt) !== []) {
            return [];
        }
        $pool = $this->pool($item->identifier);
        if ($pool === []) {
            return [];
        }
        $options = [];
        for ($slot = 0; $slot < 3; ++$slot) {
            $entropy = self::entropy($seed, $item->identifier, $slot);
            $base = max(1, intdiv($bookshelves * 2 + 3, 3));
            $required = match ($slot) {
                0 => max(1, intdiv($base, 2) + ($entropy % max(1, intdiv($bookshelves, 2) + 1))),
                1 => max(1, $base + ($entropy % max(1, $bookshelves + 1))),
                2 => max($bookshelves * 2, $base + $bookshelves + ($entropy % max(1, $bookshelves + 1))),
            };
            $enchantments = [];
            $maximumOffers = $required >= 25 ? 3 : ($required >= 12 ? 2 : 1);
            for ($offset = 0; $offset < $maximumOffers; ++$offset) {
                $definition = $pool[($entropy + $offset * 7) % count($pool)];
                if (isset($enchantments[$definition->identifier])
                    || !$this->compatible($definition, array_keys($enchantments))) {
                    continue;
                }
                $enchantments[$definition->identifier] = self::levelForPower($definition, $required);
            }
            $options[] = new EnchantingOption($slot, min(30, $required), $slot + 1, $seed, $enchantments);
        }
        return $options;
    }

    public function apply(
        ContainerItemStack $item,
        EnchantingOption $selected,
        int $playerLevel,
        int $availableLapis,
    ): ?EnchantingProcessResult {
        if ($playerLevel < 0 || $availableLapis < 0) {
            throw new InvalidArgumentException('Enchanting balances cannot be negative.');
        }
        if ($playerLevel < $selected->requiredLevel || $availableLapis < $selected->lapisCost) {
            return null;
        }
        $merged = WorkstationItemData::enchantments($item->nbt);
        foreach ($selected->enchantments as $identifier => $level) {
            $merged[$identifier] = max($merged[$identifier] ?? 0, $level);
        }
        return new EnchantingProcessResult(
            new ContainerItemStack(
                $item->identifier === 'minecraft:book' ? 'minecraft:enchanted_book' : $item->identifier,
                1,
                $item->damage,
                WorkstationItemData::withEnchantments($item->nbt, $merged),
                $item->auxValue,
            ),
            $selected->lapisCost,
            $selected->lapisCost,
        );
    }

    /** @return list<EnchantmentDefinition> */
    private function pool(string $identifier): array
    {
        if ($identifier === 'minecraft:book') {
            return array_values(array_filter(
                $this->enchantments->all(),
                static fn(EnchantmentDefinition $definition): bool => $definition->discoverable
                    && !$definition->treasure && !$definition->curse,
            ));
        }
        if ($this->items === null || !$this->items->has($identifier)) {
            return [];
        }
        $type = $this->items->type($identifier);
        return array_values(array_filter(
            $this->enchantments->all(),
            static fn(EnchantmentDefinition $definition): bool => $definition->discoverable
                && !$definition->treasure && !$definition->curse
                && EnchantmentApplicability::accepts($definition, $type),
        ));
    }

    /** @param list<string> $selected */
    private function compatible(EnchantmentDefinition $candidate, array $selected): bool
    {
        foreach ($selected as $identifier) {
            $other = $this->enchantments->find($identifier);
            if ($candidate->conflictsWith($identifier) || $other?->conflictsWith($candidate->identifier) === true) {
                return false;
            }
        }
        return true;
    }

    private static function levelForPower(EnchantmentDefinition $definition, int $power): int
    {
        for ($level = $definition->maximumLevel; $level >= 1; --$level) {
            if ($power >= $definition->minimumCostForLevel($level)) {
                return $level;
            }
        }
        return 1;
    }

    private static function entropy(int $seed, string $identifier, int $slot): int
    {
        $hash = hash('sha256', $seed . "\0" . $identifier . "\0" . $slot, true);
        $decoded = unpack('Vvalue', substr($hash, 0, 4));
        if ($decoded === false || !is_int($decoded['value'] ?? null)) {
            throw new \LogicException('Unable to decode enchanting entropy.');
        }
        return $decoded['value'] & 0x7fffffff;
    }
}
