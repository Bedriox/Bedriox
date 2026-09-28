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

namespace Bedriox\Server\Runtime;

/** Shared per-tick admission budget for all player chunk streams. */
final class ChunkStreamingBudget
{
    public function __construct(
        private int $generationRemaining,
        private int $preparationRemaining,
        private int $deliveryRemaining,
        private readonly ?self $parent = null,
        private readonly ?int $deadlineNanoseconds = null,
    ) {
        if ($generationRemaining < 0 || $preparationRemaining < 0 || $deliveryRemaining < 0
            || ($deadlineNanoseconds !== null && $deadlineNanoseconds < 1)) {
            throw new \InvalidArgumentException('Chunk streaming budgets cannot be negative.');
        }
    }

    public function claimGeneration(): bool
    {
        if (!$this->hasTimeRemaining()
            || $this->generationRemaining < 1
            || ($this->parent !== null && !$this->parent->claimGeneration())) {
            return false;
        }
        --$this->generationRemaining;

        return true;
    }

    public function claimPreparation(): bool
    {
        if (!$this->hasTimeRemaining()
            || $this->preparationRemaining < 1
            || ($this->parent !== null && !$this->parent->claimPreparation())) {
            return false;
        }
        --$this->preparationRemaining;

        return true;
    }

    public function hasDeliveryCapacity(): bool
    {
        return $this->hasTimeRemaining()
            && $this->deliveryRemaining > 0
            && ($this->parent === null || $this->parent->hasDeliveryCapacity());
    }

    public function hasCapacity(): bool
    {
        return $this->hasTimeRemaining()
            && (($this->generationRemaining > 0 && ($this->parent === null || $this->parent->hasGenerationCapacity()))
            || ($this->preparationRemaining > 0 && ($this->parent === null || $this->parent->hasPreparationCapacity()))
            || $this->hasDeliveryCapacity());
    }

    public function claimDelivery(): bool
    {
        if (!$this->hasTimeRemaining()
            || $this->deliveryRemaining < 1
            || ($this->parent !== null && !$this->parent->claimDelivery())) {
            return false;
        }
        --$this->deliveryRemaining;

        return true;
    }

    /** Creates a fair local allowance whose successful claims also consume this shared budget. */
    public function slice(int $generation, int $preparation, int $delivery): self
    {
        return new self($generation, $preparation, $delivery, $this);
    }

    private function hasGenerationCapacity(): bool
    {
        return $this->hasTimeRemaining()
            && $this->generationRemaining > 0
            && ($this->parent === null || $this->parent->hasGenerationCapacity());
    }

    private function hasPreparationCapacity(): bool
    {
        return $this->hasTimeRemaining()
            && $this->preparationRemaining > 0
            && ($this->parent === null || $this->parent->hasPreparationCapacity());
    }

    private function hasTimeRemaining(): bool
    {
        return $this->deadlineNanoseconds === null || hrtime(true) < $this->deadlineNanoseconds;
    }
}
