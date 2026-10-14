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

namespace Bedriox\Server\World\Generation\Vegetation;

/** Distinct procedural silhouettes used by the overworld vegetation planner. */
enum TreeArchetype: string
{
    case OakCompact = 'oak_compact';
    case OakBroad = 'oak_broad';
    case OakTall = 'oak_tall';
    case OakForked = 'oak_forked';
    case OakLeaning = 'oak_leaning';
    case BirchSlender = 'birch_slender';
    case BirchTall = 'birch_tall';
    case BirchForked = 'birch_forked';
    case SpruceConical = 'spruce_conical';
    case SpruceTall = 'spruce_tall';
    case SpruceWide = 'spruce_wide';
    case DarkOakBroad = 'dark_oak_broad';
    case DarkOakTall = 'dark_oak_tall';
    case PaleOakGnarled = 'pale_oak_gnarled';
    case PaleOakTall = 'pale_oak_tall';
    case AcaciaFlat = 'acacia_flat';
    case AcaciaSplit = 'acacia_split';
    case JungleTall = 'jungle_tall';
    case JungleBranching = 'jungle_branching';
    case MangroveArched = 'mangrove_arched';
    case MangroveBroad = 'mangrove_broad';
    case SwampOak = 'swamp_oak';
    case CherryWide = 'cherry_wide';
    case CherryTall = 'cherry_tall';

    public function logIdentifier(): string
    {
        return match ($this) {
            self::BirchSlender, self::BirchTall, self::BirchForked => 'minecraft:birch_log',
            self::SpruceConical, self::SpruceTall, self::SpruceWide => 'minecraft:spruce_log',
            self::DarkOakBroad, self::DarkOakTall => 'minecraft:dark_oak_log',
            self::PaleOakGnarled, self::PaleOakTall => 'minecraft:pale_oak_log',
            self::AcaciaFlat, self::AcaciaSplit => 'minecraft:acacia_log',
            self::JungleTall, self::JungleBranching => 'minecraft:jungle_log',
            self::MangroveArched, self::MangroveBroad => 'minecraft:mangrove_log',
            self::CherryWide, self::CherryTall => 'minecraft:cherry_log',
            default => 'minecraft:oak_log',
        };
    }

    public function leavesIdentifier(): string
    {
        return match ($this) {
            self::BirchSlender, self::BirchTall, self::BirchForked => 'minecraft:birch_leaves',
            self::SpruceConical, self::SpruceTall, self::SpruceWide => 'minecraft:spruce_leaves',
            self::DarkOakBroad, self::DarkOakTall => 'minecraft:dark_oak_leaves',
            self::PaleOakGnarled, self::PaleOakTall => 'minecraft:pale_oak_leaves',
            self::AcaciaFlat, self::AcaciaSplit => 'minecraft:acacia_leaves',
            self::JungleTall, self::JungleBranching => 'minecraft:jungle_leaves',
            self::MangroveArched, self::MangroveBroad => 'minecraft:mangrove_leaves',
            self::CherryWide, self::CherryTall => 'minecraft:cherry_leaves',
            default => 'minecraft:oak_leaves',
        };
    }

    public function baseHeight(): int
    {
        return match ($this) {
            self::OakCompact => 4,
            self::OakBroad, self::BirchSlender, self::AcaciaFlat => 5,
            self::OakForked, self::OakLeaning, self::BirchForked, self::SpruceConical,
            self::DarkOakBroad, self::PaleOakGnarled, self::AcaciaSplit, self::SwampOak,
            self::CherryWide => 6,
            self::OakTall, self::BirchTall, self::SpruceWide, self::DarkOakTall,
            self::MangroveArched, self::CherryTall => 8,
            self::SpruceTall, self::PaleOakTall, self::JungleTall, self::MangroveBroad => 10,
            self::JungleBranching => 12,
        };
    }

    public function heightVariation(): int
    {
        return match ($this) {
            self::OakCompact, self::BirchSlender, self::AcaciaFlat => 2,
            self::OakBroad, self::OakForked, self::OakLeaning, self::BirchForked,
            self::SpruceConical, self::DarkOakBroad, self::PaleOakGnarled,
            self::AcaciaSplit, self::SwampOak, self::CherryWide => 3,
            default => 5,
        };
    }

    public function maximumRadius(): int
    {
        return match ($this) {
            self::OakCompact, self::BirchSlender, self::BirchTall, self::SpruceConical,
            self::SpruceTall => 3,
            self::OakBroad, self::OakTall, self::OakForked, self::OakLeaning,
            self::BirchForked, self::SpruceWide, self::AcaciaFlat, self::SwampOak,
            self::CherryTall => 4,
            default => 5,
        };
    }
}
