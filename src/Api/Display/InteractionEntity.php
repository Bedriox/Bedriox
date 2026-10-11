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

namespace Bedriox\Api\Display;

use Bedriox\Api\World\Position;
use Closure;
use InvalidArgumentException;

/** Invisible, non-colliding interaction region optionally attached to a display. */
final readonly class InteractionEntity
{
    public const float DEFAULT_WIDTH = 2.0;
    public const float DEFAULT_HEIGHT = 1.0;
    public const float MAXIMUM_SIZE = 64.0;

    /**
     * @param Closure(): array{position: Position, width: float, height: float, enabled: bool, removed: bool} $state
     * @param Closure(string, mixed): void $mutate
     * @param Closure(): void $remove
     * @internal The server owns interaction-entity construction.
     */
    public function __construct(
        private string $uniqueId,
        private Closure $state,
        private Closure $mutate,
        private Closure $remove,
    ) {}

    public function getUniqueId(): string
    {
        return $this->uniqueId;
    }

    public function getPosition(): Position
    {
        return ($this->state)()['position'];
    }

    public function getWidth(): float
    {
        return ($this->state)()['width'];
    }

    public function getHeight(): float
    {
        return ($this->state)()['height'];
    }

    public function setSize(float $width, float $height): void
    {
        self::validateSize($width, $height);
        ($this->mutate)('size', [$width, $height]);
    }

    public function isEnabled(): bool
    {
        return ($this->state)()['enabled'];
    }

    public function setEnabled(bool $enabled): void
    {
        ($this->mutate)('enabled', $enabled);
    }

    public function isRemoved(): bool
    {
        return ($this->state)()['removed'];
    }

    public function remove(): void
    {
        ($this->remove)();
    }

    public static function validateSize(float $width, float $height): void
    {
        if (!is_finite($width) || !is_finite($height)
            || $width <= 0.0 || $height <= 0.0
            || $width > self::MAXIMUM_SIZE || $height > self::MAXIMUM_SIZE) {
            throw new InvalidArgumentException('Interaction dimensions must be finite, positive, and bounded.');
        }
    }
}
