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

namespace Bedriox\Server\World\Environment\Fluid;

use Bedriox\Data\CanonicalBlockState;
use InvalidArgumentException;

final readonly class FluidState
{
    public function __construct(
        public FluidType $type,
        public int $depth,
        public bool $falling,
    ) {
        if ($depth < 0 || $depth > 7) {
            throw new InvalidArgumentException('Fluid depth must be between 0 and 7.');
        }
    }

    public static function source(FluidType $type): self
    {
        return new self($type, 0, false);
    }

    public static function falling(FluidType $type): self
    {
        return new self($type, 0, true);
    }

    public static function fromCanonical(CanonicalBlockState $state): ?self
    {
        $type = FluidType::tryFrom($state->identifier());
        if ($type === null) {
            return null;
        }

        $encodedDepth = $state->properties()['liquid_depth'] ?? 0;
        if (!is_int($encodedDepth) || $encodedDepth < 0 || $encodedDepth > 15) {
            throw new InvalidArgumentException('Canonical liquid_depth must be an integer between 0 and 15.');
        }

        return new self($type, $encodedDepth & 7, ($encodedDepth & 8) !== 0);
    }

    public function isSource(): bool
    {
        return $this->depth === 0 && !$this->falling;
    }

    public function height(): float
    {
        return $this->falling ? 1.0 : (8 - $this->depth) / 9;
    }

    public function canonicalState(): CanonicalBlockState
    {
        return CanonicalBlockState::from($this->type->value, [
            'liquid_depth' => $this->depth | ($this->falling ? 8 : 0),
        ]);
    }
}
