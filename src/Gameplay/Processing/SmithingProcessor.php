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
use Bedriox\Data\RecipeIngredient;
use Bedriox\Data\RecipeRegistry;
use Bedriox\Server\Gameplay\Crafting\RecipeItemTagRegistry;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;

/** Matches smithing inputs and derives transform or trim output from authoritative stacks. */
final readonly class SmithingProcessor
{
    public function __construct(
        private RecipeRegistry $recipes,
        private RecipeItemTagRegistry $tags,
    ) {}

    public function process(
        ContainerItemStack $template,
        ContainerItemStack $base,
        ContainerItemStack $addition,
    ): ?WorkstationResult {
        foreach ($this->recipes->smithingTransformRecipes() as $recipe) {
            if (!$this->matches($recipe->template(), $template)
                || !$this->matches($recipe->base(), $base)
                || !$this->matches($recipe->addition(), $addition)) {
                continue;
            }
            $output = $recipe->output();
            return new WorkstationResult(
                [0 => 1, 1 => 1, 2 => 1],
                [new ContainerItemStack(
                    $output->itemIdentifier(),
                    $output->count(),
                    $base->damage,
                    $base->nbt ?? ($output->nbt() === null ? null : ItemNbt::fromBinary($output->nbt())),
                )],
            );
        }
        foreach ($this->recipes->smithingTrimRecipes() as $recipe) {
            if (!$this->matches($recipe->template(), $template)
                || !$this->matches($recipe->base(), $base)
                || !$this->matches($recipe->addition(), $addition)) {
                continue;
            }
            $nbt = ($base->nbt ?? ItemNbt::empty())->withTag('Trim', Tag::compound([
                'Pattern' => Tag::string($template->identifier),
                'Material' => Tag::string($addition->identifier),
            ]));
            return new WorkstationResult(
                [0 => 1, 1 => 1, 2 => 1],
                [new ContainerItemStack($base->identifier, 1, $base->damage, $nbt, $base->auxValue)],
            );
        }
        return null;
    }

    private function matches(RecipeIngredient $ingredient, ContainerItemStack $stack): bool
    {
        if ($stack->count < $ingredient->count()) {
            return false;
        }
        $identifier = $ingredient->itemIdentifier();
        if ($identifier !== null) {
            return $identifier === $stack->identifier
                && ($ingredient->auxValue() === null || $ingredient->auxValue() === 32_767
                    || $ingredient->auxValue() === $stack->auxValue);
        }
        $tag = $ingredient->itemTag();
        return $tag !== null && in_array($stack->identifier, $this->tags->members($tag), true);
    }
}
