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
use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;
use InvalidArgumentException;

/** Canonical server-owned properties of one supported block state. */
final readonly class BlockType
{
    public function __construct(
        public CanonicalBlockState $state,
        public float $hardness,
        public ?ToolType $preferredTool,
        public ?ToolTier $requiredTier,
        public BlockDropKind $dropKind,
        public bool $hasItemForm = true,
        public ?string $itemFormIdentifier = null,
    ) {
        if (!is_finite($hardness) || $hardness < -1.0) {
            throw new InvalidArgumentException('Block hardness must be finite and at least negative one.');
        }
        if ($requiredTier !== null && $preferredTool === null) {
            throw new InvalidArgumentException('A tier requirement needs a preferred tool type.');
        }
        if ($itemFormIdentifier !== null
            && preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $itemFormIdentifier) !== 1) {
            throw new InvalidArgumentException('Block item-form identifier must be canonical and namespaced.');
        }
        if (!$hasItemForm && $itemFormIdentifier !== null) {
            throw new InvalidArgumentException('A block without an item form cannot define an item identifier.');
        }
    }

    public function identifier(): string
    {
        return $this->state->identifier();
    }

    public function isBreakable(): bool
    {
        return $this->hardness >= 0.0;
    }

    public function itemFormState(): ?CanonicalBlockState
    {
        return $this->hasItemForm ? $this->state : null;
    }

    public function itemIdentifier(): ?string
    {
        return $this->hasItemForm ? ($this->itemFormIdentifier ?? $this->identifier()) : null;
    }

    public function withItemIdentifier(string $identifier): self
    {
        return new self(
            $this->state,
            $this->hardness,
            $this->preferredTool,
            $this->requiredTier,
            $this->dropKind,
            true,
            $identifier,
        );
    }
}
