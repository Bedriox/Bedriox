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

use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Data\RecipeRegistry;
use Bedriox\Server\Gameplay\Crafting\RecipeItemTagRegistry;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;

/** Cohesive deterministic entry point for transient workstation preview and commit planning. */
final readonly class TransientWorkstationProcessor
{
    private StonecutterProcessor $stonecutter;
    private SmithingProcessor $smithing;
    private AnvilProcessor $anvil;
    private GrindstoneProcessor $grindstone;
    private EnchantingProcessor $enchanting;
    private LoomProcessor $loom;
    private CartographyProcessor $cartography;

    public function __construct(RecipeRegistry $recipes, RecipeItemTagRegistry $tags)
    {
        $this->stonecutter = new StonecutterProcessor($recipes);
        $this->smithing = new SmithingProcessor($recipes, $tags);
        $this->anvil = new AnvilProcessor();
        $this->grindstone = new GrindstoneProcessor();
        $this->enchanting = new EnchantingProcessor();
        $this->loom = new LoomProcessor();
        $this->cartography = new CartographyProcessor();
    }

    /**
     * Evaluates a complete immutable slot snapshot. The caller must revalidate the same snapshot before commit.
     *
     * @param list<ContainerItemStack|null> $slots
     */
    public function evaluate(
        ContainerType $type,
        array $slots,
        WorkstationEvaluationContext $context,
    ): ?WorkstationResult {
        $expected = $type->slotCount();
        if ($expected === null || count($slots) !== $expected) {
            throw new InvalidArgumentException('Workstation slot snapshot does not match its container layout.');
        }
        return match ($type) {
            ContainerType::STONECUTTER => $context->recipeSourceIndex === null || $slots[0] === null
                ? null : $this->stonecutter->process($slots[0], $context->recipeSourceIndex),
            ContainerType::SMITHING_TABLE => $slots[0] === null || $slots[1] === null || $slots[2] === null
                ? null : $this->smithing->process($slots[0], $slots[1], $slots[2]),
            ContainerType::ANVIL => $slots[0] === null
                ? null : $this->anvil->process($slots[0], $slots[1], $context->name, $context->maximumDurability),
            ContainerType::GRINDSTONE => $slots[0] === null
                ? null : $this->grindstone->process($slots[0], $slots[1], $context->maximumDurability),
            ContainerType::ENCHANTING_TABLE => $slots[0] === null
                ? null : $this->evaluateEnchanting($slots[0], $slots[1], $context),
            ContainerType::LOOM => $slots[0] === null || $slots[1] === null || $context->loomPattern === null
                ? null : $this->loom->process(
                    $slots[0],
                    $slots[1],
                    $context->loomPattern,
                    $slots[2] ?? null,
                ),
            ContainerType::CARTOGRAPHY_TABLE => $slots[0] === null || $context->cartographyOperation === null
                ? null : $this->cartography->process(
                    $slots[0],
                    $slots[1],
                    $context->cartographyOperation,
                    $context->name,
                ),
            default => null,
        };
    }

    public function stonecutter(): StonecutterProcessor
    {
        return $this->stonecutter;
    }
    public function smithing(): SmithingProcessor
    {
        return $this->smithing;
    }
    public function anvil(): AnvilProcessor
    {
        return $this->anvil;
    }
    public function grindstone(): GrindstoneProcessor
    {
        return $this->grindstone;
    }
    public function enchanting(): EnchantingProcessor
    {
        return $this->enchanting;
    }
    public function loom(): LoomProcessor
    {
        return $this->loom;
    }
    public function cartography(): CartographyProcessor
    {
        return $this->cartography;
    }

    private function evaluateEnchanting(
        ContainerItemStack $item,
        ?ContainerItemStack $lapis,
        WorkstationEvaluationContext $context,
    ): ?WorkstationResult {
        $options = $this->enchanting->options($item, $context->bookshelves, $context->enchantmentSeed);
        $option = $options[$context->enchantingOption] ?? null;
        if ($option === null) {
            return null;
        }
        if ($lapis?->identifier !== 'minecraft:lapis_lazuli' || $lapis->count < $option->lapisCost
            || $context->availableLapis < $option->lapisCost) {
            return null;
        }
        $result = $this->enchanting->apply($item, $option, $context->playerLevel, $context->availableLapis);
        if ($result === null) {
            return null;
        }
        return new WorkstationResult(
            [0 => 1, 1 => $result->lapisCost],
            [$result->item],
            experienceLevelCost: $result->experienceLevelCost,
            lapisCost: $result->lapisCost,
        );
    }
}
