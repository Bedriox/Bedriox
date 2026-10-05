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

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Entity\Capability\Ageable;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\ItemStack;

final readonly class NetherMobLootTable implements LootTable
{
    public function __construct(private VanillaEntityType $type) {}

    public function roll(LootContext $context, LootRandomSource $random): array
    {
        if ($context->subject instanceof Ageable && $context->subject->isBaby()) {
            return [];
        }

        return match ($this->type) {
            VanillaEntityType::BLAZE => self::optional(
                'minecraft:blaze_rod',
                $random->nextInt(0, 1 + $context->lootingLevel),
            ),
            VanillaEntityType::GHAST => [
                ...self::optional('minecraft:ghast_tear', $random->nextInt(0, 1 + $context->lootingLevel)),
                ...self::optional('minecraft:gunpowder', $random->nextInt(0, 2 + $context->lootingLevel)),
            ],
            VanillaEntityType::HOGLIN => [
                new ItemStack(
                    $context->burning ? 'minecraft:cooked_porkchop' : 'minecraft:porkchop',
                    $random->nextInt(2, 4 + $context->lootingLevel),
                ),
                ...self::optional('minecraft:leather', $random->nextInt(0, 1 + $context->lootingLevel)),
            ],
            VanillaEntityType::STRIDER => [
                new ItemStack('minecraft:string', $random->nextInt(2, 5 + $context->lootingLevel)),
            ],
            VanillaEntityType::ZOGLIN => [
                new ItemStack('minecraft:rotten_flesh', $random->nextInt(1, 3 + $context->lootingLevel)),
            ],
            VanillaEntityType::ZOMBIFIED_PIGLIN => [
                ...self::optional('minecraft:rotten_flesh', $random->nextInt(0, 1 + $context->lootingLevel)),
                ...self::optional('minecraft:gold_nugget', $random->nextInt(0, 1 + $context->lootingLevel)),
            ],
            default => [],
        };
    }

    /** @return list<ItemStack> */
    private static function optional(string $identifier, int $count): array
    {
        return $count > 0 ? [new ItemStack($identifier, $count)] : [];
    }
}
