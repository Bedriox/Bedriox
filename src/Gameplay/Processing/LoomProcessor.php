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
use Bedriox\Server\World\BlockEntity\ContainerItemStack;

/** Applies one validated banner pattern while enforcing Bedrock's six-layer limit. */
final readonly class LoomProcessor
{
    private const int MAXIMUM_PATTERNS = 6;
    private const array PATTERNS = [
        'bo', 'bri', 'mc', 'cre', 'cr', 'cbo', 'lud', 'rd', 'ld', 'rud', 'flo', 'flw', 'glb', 'gra',
        'gru', 'gus', 'hh', 'hhb', 'vh', 'vhr', 'moj', 'pig', 'mr', 'sku', 'ss', 'bl', 'br', 'tl',
        'tr', 'sc', 'bs', 'cs', 'dls', 'drs', 'ls', 'ms', 'rs', 'ts', 'bt', 'tt', 'bts', 'tts',
    ];
    private const array PATTERN_ITEMS = [
        'cre' => 'minecraft:creeper_banner_pattern',
        'flo' => 'minecraft:flower_banner_pattern',
        'flw' => 'minecraft:flow_banner_pattern',
        'glb' => 'minecraft:globe_banner_pattern',
        'gus' => 'minecraft:guster_banner_pattern',
        'moj' => 'minecraft:thing_banner_pattern',
        'pig' => 'minecraft:piglin_banner_pattern',
        'sku' => 'minecraft:skull_banner_pattern',
    ];

    public function process(
        ContainerItemStack $banner,
        ContainerItemStack $dye,
        string $pattern,
        ?ContainerItemStack $patternItem = null,
    ): ?WorkstationResult {
        if (!str_ends_with($banner->identifier, '_banner') || !str_ends_with($dye->identifier, '_dye')
            || !in_array($pattern, self::PATTERNS, true)
            || ((self::PATTERN_ITEMS[$pattern] ?? null) !== $patternItem?->identifier)) {
            return null;
        }
        $existingTag = $banner->nbt?->tag('Patterns');
        $patterns = [];
        if ($existingTag !== null) {
            $existing = $existingTag->value();
            if ($existingTag->type() !== TagType::LIST || !is_array($existing)
                || count($existing) >= self::MAXIMUM_PATTERNS) {
                return null;
            }
            foreach ($existing as $entry) {
                if (!$entry instanceof Tag || $entry->type() !== TagType::COMPOUND) {
                    return null;
                }
                $patterns[] = $entry;
            }
        }
        $patterns[] = Tag::compound([
            'Pattern' => Tag::string($pattern),
            'Color' => Tag::string(substr($dye->identifier, 10, -4)),
        ]);
        $nbt = ($banner->nbt ?? ItemNbt::empty())->withTag('Patterns', Tag::list(TagType::COMPOUND, $patterns));
        $consumed = [0 => 1, 1 => 1];
        if (isset(self::PATTERN_ITEMS[$pattern])) {
            $consumed[2] = 1;
        }
        return new WorkstationResult(
            $consumed,
            [new ContainerItemStack($banner->identifier, 1, $banner->damage, $nbt, $banner->auxValue)],
        );
    }
}
