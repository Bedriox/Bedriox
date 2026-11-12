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

namespace Bedriox\Api\Map;

use InvalidArgumentException;

/** Immutable row-major RGBA image used by map canvases and static maps. */
final readonly class MapImage
{
    public const int MAXIMUM_DIMENSION = 2_560;
    public const int MAXIMUM_BYTES = self::MAXIMUM_DIMENSION * self::MAXIMUM_DIMENSION * 4;

    public function __construct(
        public int $width,
        public int $height,
        private string $rgba,
    ) {
        if ($width < 1 || $height < 1 || $width > self::MAXIMUM_DIMENSION || $height > self::MAXIMUM_DIMENSION
            || strlen($rgba) !== $width * $height * 4) {
            throw new InvalidArgumentException('Map image dimensions and RGBA payload do not agree.');
        }
    }

    public static function fromRgba(int $width, int $height, string $rgba): self
    {
        return new self($width, $height, $rgba);
    }

    public function rgba(): string
    {
        return $this->rgba;
    }

    public function tile(int $column, int $row, int $tileWidth = 128, int $tileHeight = 128): self
    {
        if ($column < 0 || $row < 0 || $tileWidth < 1 || $tileHeight < 1
            || ($column + 1) * $tileWidth > $this->width || ($row + 1) * $tileHeight > $this->height) {
            throw new InvalidArgumentException('Map image tile lies outside the source image.');
        }
        $pixels = '';
        $sourceX = $column * $tileWidth;
        $sourceY = $row * $tileHeight;
        for ($y = 0; $y < $tileHeight; ++$y) {
            $pixels .= substr($this->rgba, (($sourceY + $y) * $this->width + $sourceX) * 4, $tileWidth * 4);
        }

        return new self($tileWidth, $tileHeight, $pixels);
    }
}
