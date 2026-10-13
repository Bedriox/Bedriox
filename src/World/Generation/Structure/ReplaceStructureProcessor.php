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

namespace Bedriox\Server\World\Generation\Structure;

use Bedriox\Data\CanonicalBlockState;
use InvalidArgumentException;

final readonly class ReplaceStructureProcessor implements StructureProcessor
{
    /** @var array<string, CanonicalBlockState> */
    private array $replacements;

    /** @param array<string, mixed> $replacements canonical-state key to replacement state */
    public function __construct(array $replacements)
    {
        if (count($replacements) > 4_096) {
            throw new InvalidArgumentException('Structure replacement processor exceeds its bounded mapping limit.');
        }
        foreach ($replacements as $replacement) {
            if (!$replacement instanceof CanonicalBlockState) {
                throw new InvalidArgumentException('Structure replacement processor contains an invalid state.');
            }
        }
        $this->replacements = $replacements;
    }

    public function process(PlacedStructureBlock $block): PlacedStructureBlock
    {
        $replacement = $this->replacements[$block->state->canonicalKey()] ?? null;
        return $replacement instanceof CanonicalBlockState
            ? new PlacedStructureBlock($block->coordinate, $replacement)
            : $block;
    }
}
