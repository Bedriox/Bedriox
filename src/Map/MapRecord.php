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

namespace Bedriox\Server\Map;

use Bedriox\Api\Map\MapScale;
use Bedriox\Api\World\DimensionType;
use InvalidArgumentException;

final readonly class MapRecord
{
    public const int WIDTH = 128;
    public const int HEIGHT = 128;
    public const int PIXEL_BYTES = self::WIDTH * self::HEIGHT * 4;

    public function __construct(
        public int $id,
        public DimensionType $dimension,
        public int $centerX,
        public int $centerZ,
        public MapScale $scale,
        public bool $locked,
        public bool $tracking,
        public string $rgba,
        public int $revision = 1,
    ) {
        if ($id < 1 || strlen($rgba) !== self::PIXEL_BYTES || $revision < 1) {
            throw new InvalidArgumentException('Map record contains invalid identity, pixels, or revision.');
        }
    }

    public function withLocked(bool $locked): self
    {
        return $locked === $this->locked ? $this : new self(
            $this->id,
            $this->dimension,
            $this->centerX,
            $this->centerZ,
            $this->scale,
            $locked,
            $this->tracking,
            $this->rgba,
            $this->revision + 1,
        );
    }

    public function withPixels(string $rgba): self
    {
        return $rgba === $this->rgba ? $this : new self(
            $this->id,
            $this->dimension,
            $this->centerX,
            $this->centerZ,
            $this->scale,
            $this->locked,
            $this->tracking,
            $rgba,
            $this->revision + 1,
        );
    }
}
