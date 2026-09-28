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

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Persistent storage owned by one chest, barrel, or shulker-box block. */
final readonly class ContainerBlockEntity extends BlockEntity
{
    public const int STORAGE_SLOT_COUNT = 27;

    public function __construct(
        BlockEntityType $type,
        BlockPosition $position,
        public ContainerInventory $inventory,
        public ?string $customName = null,
        public ?BlockPosition $pairedPosition = null,
        public bool $pairLead = false,
        public int $facing = 2,
        int $revision = 0,
    ) {
        parent::__construct($type, $position, $revision);
        if (!$type->ownsPersistentInventory()) {
            throw new InvalidArgumentException('This block-entity type does not own persistent container contents.');
        }
        if ($inventory->size !== self::STORAGE_SLOT_COUNT) {
            throw new InvalidArgumentException('Storage block entities must own exactly 27 slots.');
        }
        self::validateCustomName($customName);
        if ($pairedPosition !== null) {
            if ($type !== BlockEntityType::Chest
                || $pairedPosition->y !== $position->y
                || abs($pairedPosition->x - $position->x) + abs($pairedPosition->z - $position->z) !== 1) {
                throw new InvalidArgumentException('Only horizontally adjacent chest block entities may be paired.');
            }
        } elseif ($pairLead) {
            throw new InvalidArgumentException('An unpaired chest cannot be the pair lead.');
        }
        if ($facing < 0 || $facing > 5) {
            throw new InvalidArgumentException('Block-entity facing is outside the supported range.');
        }
    }

    public static function empty(BlockEntityType $type, BlockPosition $position): self
    {
        return new self($type, $position, ContainerInventory::empty(self::STORAGE_SLOT_COUNT));
    }

    public function withInventory(ContainerInventory $inventory): self
    {
        if ($inventory === $this->inventory) {
            return $this;
        }

        return $this->replace(inventory: $inventory);
    }

    public function withCustomName(?string $customName): self
    {
        self::validateCustomName($customName);
        if ($customName === $this->customName) {
            return $this;
        }

        return $this->replace(customName: $customName, replaceName: true);
    }

    public function withPair(BlockPosition $position, bool $lead): self
    {
        if ($this->pairedPosition?->equals($position) === true && $this->pairLead === $lead) {
            return $this;
        }

        return $this->replace(pairedPosition: $position, pairLead: $lead, replacePair: true);
    }

    public function withoutPair(): self
    {
        if ($this->pairedPosition === null) {
            return $this;
        }

        return $this->replace(replacePair: true);
    }

    public function withFacing(int $facing): self
    {
        if ($facing === $this->facing) {
            return $this;
        }

        return $this->replace(facing: $facing);
    }

    private function replace(
        ?ContainerInventory $inventory = null,
        ?string $customName = null,
        bool $replaceName = false,
        ?BlockPosition $pairedPosition = null,
        bool $pairLead = false,
        bool $replacePair = false,
        ?int $facing = null,
    ): self {
        if ($this->revision === PHP_INT_MAX) {
            throw new \OverflowException('Block-entity revision space is exhausted.');
        }

        return new self(
            $this->type,
            $this->position,
            $inventory ?? $this->inventory,
            $replaceName ? $customName : $this->customName,
            $replacePair ? $pairedPosition : $this->pairedPosition,
            $replacePair ? $pairLead : $this->pairLead,
            $facing ?? $this->facing,
            $this->revision + 1,
        );
    }

    private static function validateCustomName(?string $customName): void
    {
        if ($customName !== null && ($customName === '' || strlen($customName) > 256
            || preg_match('//u', $customName) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $customName) === 1)) {
            throw new InvalidArgumentException('Container custom name is invalid or exceeds its size limit.');
        }
    }
}
