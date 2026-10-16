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

namespace Bedriox\Api\Entity\Value;

enum VillagerWorkstation: string
{
    case COMPOSTER = 'minecraft:composter';
    case BARREL = 'minecraft:barrel';
    case LOOM = 'minecraft:loom';
    case FLETCHING_TABLE = 'minecraft:fletching_table';
    case LECTERN = 'minecraft:lectern';
    case CARTOGRAPHY_TABLE = 'minecraft:cartography_table';
    case BREWING_STAND = 'minecraft:brewing_stand';
    case BLAST_FURNACE = 'minecraft:blast_furnace';
    case GRINDSTONE = 'minecraft:grindstone';
    case SMITHING_TABLE = 'minecraft:smithing_table';
    case SMOKER = 'minecraft:smoker';
    case CAULDRON = 'minecraft:cauldron';
    case STONECUTTER = 'minecraft:stonecutter';

    public function profession(): VillagerProfession
    {
        return match ($this) {
            self::COMPOSTER => VillagerProfession::FARMER,
            self::BARREL => VillagerProfession::FISHERMAN,
            self::LOOM => VillagerProfession::SHEPHERD,
            self::FLETCHING_TABLE => VillagerProfession::FLETCHER,
            self::LECTERN => VillagerProfession::LIBRARIAN,
            self::CARTOGRAPHY_TABLE => VillagerProfession::CARTOGRAPHER,
            self::BREWING_STAND => VillagerProfession::CLERIC,
            self::BLAST_FURNACE => VillagerProfession::ARMORER,
            self::GRINDSTONE => VillagerProfession::WEAPONSMITH,
            self::SMITHING_TABLE => VillagerProfession::TOOLSMITH,
            self::SMOKER => VillagerProfession::BUTCHER,
            self::CAULDRON => VillagerProfession::LEATHERWORKER,
            self::STONECUTTER => VillagerProfession::MASON,
        };
    }

    public static function forProfession(VillagerProfession $profession): ?self
    {
        foreach (self::cases() as $workstation) {
            if ($workstation->profession() === $profession) {
                return $workstation;
            }
        }

        return null;
    }
}
