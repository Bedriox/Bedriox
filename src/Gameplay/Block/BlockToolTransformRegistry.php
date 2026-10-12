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

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Precomputed vanilla block transformations selected by the server-held tool. */
final readonly class BlockToolTransformRegistry
{
    /** @var array<string, InternalBlockStateId> */
    private array $defaultStates;

    public function __construct(private BlockStateRegistry $states)
    {
        $defaults = [];
        foreach ($states->states() as $value => $state) {
            $defaults[$state->identifier()] ??= new InternalBlockStateId($value);
        }
        $this->defaultStates = $defaults;
    }

    public function resolve(
        CanonicalBlockState $current,
        string $toolIdentifier,
        int $clickedFace,
        bool $aboveIsReplaceable,
    ): ?BlockToolTransform {
        if ($clickedFace === 0) {
            return null;
        }
        if (str_ends_with($toolIdentifier, '_axe')) {
            return $this->strip($current);
        }
        if (str_ends_with($toolIdentifier, '_shovel')) {
            return $this->flatten($current, $aboveIsReplaceable);
        }
        if (str_ends_with($toolIdentifier, '_hoe')) {
            return $this->till($current, $aboveIsReplaceable);
        }

        return null;
    }

    private function strip(CanonicalBlockState $current): ?BlockToolTransform
    {
        $identifier = $current->identifier();
        if (str_starts_with($identifier, 'minecraft:stripped_')) {
            return null;
        }
        $base = substr($identifier, strlen('minecraft:'));
        if (!str_ends_with($base, '_log')
            && !str_ends_with($base, '_wood')
            && !str_ends_with($base, '_stem')
            && !str_ends_with($base, '_hyphae')
            && $base !== 'bamboo_block') {
            return null;
        }
        $targetIdentifier = 'minecraft:stripped_' . $base;
        $properties = $current->properties();
        try {
            return new BlockToolTransform($this->states->internalId(
                CanonicalBlockState::from($targetIdentifier, $properties),
            ));
        } catch (InvalidArgumentException) {
            return $this->defaultTransform($targetIdentifier);
        }
    }

    private function flatten(CanonicalBlockState $current, bool $aboveIsReplaceable): ?BlockToolTransform
    {
        if (!$aboveIsReplaceable || !in_array($current->identifier(), [
            'minecraft:grass_block',
            'minecraft:dirt',
            'minecraft:coarse_dirt',
            'minecraft:podzol',
            'minecraft:mycelium',
            'minecraft:rooted_dirt',
        ], true)) {
            return null;
        }

        return $this->defaultTransform('minecraft:grass_path');
    }

    private function till(CanonicalBlockState $current, bool $aboveIsReplaceable): ?BlockToolTransform
    {
        if (!$aboveIsReplaceable) {
            return null;
        }
        return match ($current->identifier()) {
            'minecraft:grass_block', 'minecraft:dirt', 'minecraft:grass_path' =>
                $this->defaultTransform('minecraft:farmland'),
            'minecraft:coarse_dirt' => $this->defaultTransform('minecraft:dirt'),
            'minecraft:rooted_dirt' => $this->defaultTransform('minecraft:dirt', 'minecraft:hanging_roots'),
            default => null,
        };
    }

    private function defaultTransform(string $identifier, ?string $dropIdentifier = null): ?BlockToolTransform
    {
        $state = $this->defaultStates[$identifier] ?? null;

        return $state === null ? null : new BlockToolTransform($state, $dropIdentifier);
    }
}
