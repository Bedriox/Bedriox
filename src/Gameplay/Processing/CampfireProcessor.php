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

final readonly class CampfireProcessor
{
    public function __construct(private FurnaceRecipeCatalog $recipes) {}

    public function tick(CampfireBlockEntity $state, bool $lit): CampfireTickResult
    {
        if (!$lit || !$state->active()) {
            return new CampfireTickResult($state, []);
        }
        $inventory = $state->inventory;
        $progress = $state->progressBySlot;
        $duration = $state->durationBySlot;
        $completed = [];
        foreach ($inventory->contents() as $slot => $input) {
            $recipe = CampfireRecipeResolver::match($this->recipes, $state->campfireType, $input);
            if ($recipe === null) {
                unset($progress[$slot], $duration[$slot]);
                continue;
            }
            $duration[$slot] ??= CampfireBlockEntity::DEFAULT_COOK_TIME_TICKS;
            $progress[$slot] = ($progress[$slot] ?? 0) + 1;
            if ($progress[$slot] < $duration[$slot]) {
                continue;
            }
            $completed[$slot] = $recipe->output;
            $inventory = $inventory->withStack($slot, null);
            unset($progress[$slot], $duration[$slot]);
        }
        return new CampfireTickResult($state->withState($inventory, $progress, $duration), $completed);
    }
}
