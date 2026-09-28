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

namespace Bedriox\Api\World\Generator;

use InvalidArgumentException;

final readonly class GeneratedSection
{
    public const int BLOCK_COUNT = 4_096;
    public const int MAXIMUM_PALETTE_SIZE = 256;

    /** @var non-empty-list<BlockState> */
    public array $palette;

    /**
     * @param list<mixed> $palette
     */
    public function __construct(
        public int $sectionY,
        array $palette,
        public string $paletteIndices,
    ) {
        if ($sectionY < -4 || $sectionY > 19) {
            throw new InvalidArgumentException('Generated section Y is outside the supported overworld height.');
        }
        if ($palette === [] || count($palette) > self::MAXIMUM_PALETTE_SIZE
            || strlen($paletteIndices) !== self::BLOCK_COUNT) {
            throw new InvalidArgumentException('Generated section palette is empty, oversized, or has invalid indices.');
        }
        foreach ($palette as $state) {
            if (!$state instanceof BlockState) {
                throw new InvalidArgumentException('Generated section contains an invalid block state.');
            }
        }
        $paletteSize = count($palette);
        for ($offset = 0; $offset < self::BLOCK_COUNT; ++$offset) {
            if (ord($paletteIndices[$offset]) >= $paletteSize) {
                throw new InvalidArgumentException('Generated section contains an out-of-range palette index.');
            }
        }
        /** @var non-empty-list<BlockState> $palette */
        $this->palette = $palette;
    }

    public static function uniform(int $sectionY, BlockState $state): self
    {
        return new self($sectionY, [$state], str_repeat("\x00", self::BLOCK_COUNT));
    }

    /** @param list<mixed> $layers exactly 16 uniform layers, bottom to top */
    public static function layered(int $sectionY, array $layers): self
    {
        if (count($layers) !== 16) {
            throw new InvalidArgumentException('Generated layered section must contain exactly 16 layers.');
        }
        $palette = [];
        $indicesByKey = [];
        $indices = '';
        foreach ($layers as $state) {
            if (!$state instanceof BlockState) {
                throw new InvalidArgumentException('Generated layered section contains an invalid block state.');
            }
            $key = json_encode([$state->identifier, $state->properties], JSON_THROW_ON_ERROR);
            $index = $indicesByKey[$key] ?? null;
            if (!is_int($index)) {
                $index = count($palette);
                $palette[] = $state;
                $indicesByKey[$key] = $index;
            }
            $indices .= str_repeat(chr($index), 256);
        }

        return new self($sectionY, $palette, $indices);
    }
}
