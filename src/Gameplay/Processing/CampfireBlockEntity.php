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

use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

final readonly class CampfireBlockEntity extends BlockEntity
{
    public const int SLOT_COUNT = 4;
    public const int DEFAULT_COOK_TIME_TICKS = 600;
    public const int MAXIMUM_COOK_TIME_TICKS = 20_000;

    /**
     * @param array<int, int> $progressBySlot
     * @param array<int, int> $durationBySlot
     */
    public function __construct(
        BlockPosition $position,
        public CampfireType $campfireType,
        public ContainerInventory $inventory,
        public array $progressBySlot = [],
        public array $durationBySlot = [],
        int $revision = 0,
    ) {
        parent::__construct(\Bedriox\Server\World\BlockEntity\BlockEntityType::Campfire, $position, $revision);
        if ($inventory->size !== self::SLOT_COUNT) {
            throw new InvalidArgumentException('Campfires must own exactly four cooking positions.');
        }
        foreach ($inventory->contents() as $stack) {
            if ($stack->count !== 1) {
                throw new InvalidArgumentException('Each campfire cooking position accepts exactly one item.');
            }
        }
        foreach ($progressBySlot as $slot => $progress) {
            $duration = $durationBySlot[$slot] ?? null;
            if ($slot < 0 || $slot >= self::SLOT_COUNT || $progress < 0 || $duration < 1
                || $duration > self::MAXIMUM_COOK_TIME_TICKS || $progress > $duration
                || $inventory->stackAt($slot) === null) {
                throw new InvalidArgumentException('Campfire cooking progress is malformed.');
            }
        }
        if (array_diff_key($durationBySlot, $progressBySlot) !== []) {
            throw new InvalidArgumentException('Campfire cooking durations do not match active slots.');
        }
    }

    public static function empty(CampfireType $type, BlockPosition $position): self
    {
        return new self($position, $type, ContainerInventory::empty(self::SLOT_COUNT));
    }

    /**
     * @param array<int, int> $progress
     * @param array<int, int> $duration
     */
    public function withState(ContainerInventory $inventory, array $progress, array $duration): self
    {
        return new self($this->position, $this->campfireType, $inventory, $progress, $duration, $this->revision + 1);
    }

    public function active(): bool
    {
        return $this->inventory->contents() !== [];
    }
}
