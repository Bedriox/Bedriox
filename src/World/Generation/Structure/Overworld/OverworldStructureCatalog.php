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

namespace Bedriox\Server\World\Generation\Structure\Overworld;

/** Authoritative bounded catalog for production-qualified Overworld structures. */
final class OverworldStructureCatalog
{
    /** @var array<string, OverworldStructureDefinition>|null */
    private static ?array $definitions = null;

    /** @return list<OverworldStructureDefinition> */
    public static function all(): array
    {
        return array_values(self::indexed());
    }

    public static function get(OverworldStructureFamily $family): OverworldStructureDefinition
    {
        return self::indexed()[$family->value]
            ?? throw new \InvalidArgumentException("Overworld structure family {$family->value} is not production-enabled.");
    }

    /** @return array<string, OverworldStructureDefinition> */
    private static function indexed(): array
    {
        return self::$definitions ??= self::build();
    }

    /** @return array<string, OverworldStructureDefinition> */
    private static function build(): array
    {
        $definitions = [
            new OverworldStructureDefinition(OverworldStructureFamily::Village, 24, 6, 48, 10_101, false, true, ['plains', 'desert', 'savanna', 'taiga', 'snowy'], ['plains', 'desert', 'savanna', 'taiga', 'ice_plains']),
            new OverworldStructureDefinition(OverworldStructureFamily::Dungeon, 12, 4, 8, 10_701, true, true, ['standard']),
            new OverworldStructureDefinition(OverworldStructureFamily::Mineshaft, 20, 5, 52, 11_101, true, true, ['oak', 'badlands']),
            new OverworldStructureDefinition(OverworldStructureFamily::Stronghold, 56, 12, 60, 11_501, true, true, ['standard']),
        ];
        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->family->value] = $definition;
        }

        return $indexed;
    }
}
