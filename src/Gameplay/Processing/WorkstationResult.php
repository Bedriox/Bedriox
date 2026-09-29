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

use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;

/** Immutable result proposed by a workstation before its inventory transaction is committed. */
final readonly class WorkstationResult
{
    /** @var array<int, int> */
    public array $consumedBySlot;

    /** @var list<ContainerItemStack> */
    public array $outputs;

    /**
     * @param array<int, int> $consumedBySlot
     * @param list<ContainerItemStack> $outputs
     */
    public function __construct(
        array $consumedBySlot,
        array $outputs,
        public int $experience = 0,
        public int $experienceLevelCost = 0,
        public int $lapisCost = 0,
    ) {
        if ($consumedBySlot === [] || count($consumedBySlot) > 9 || $outputs === [] || count($outputs) > 8) {
            throw new InvalidArgumentException('Workstation result is empty or exceeds its bounded shape.');
        }
        foreach ($consumedBySlot as $slot => $count) {
            if ($slot < 0 || $slot > 63 || $count < 1 || $count > 64) {
                throw new InvalidArgumentException('Workstation consumption contains an invalid slot or count.');
            }
        }
        if ($experience < 0 || $experience > 1_000_000) {
            throw new InvalidArgumentException('Workstation experience is outside its supported range.');
        }
        if ($experienceLevelCost < 0 || $experienceLevelCost > 39 || $lapisCost < 0 || $lapisCost > 64) {
            throw new InvalidArgumentException('Workstation input costs are outside their supported bounds.');
        }
        ksort($consumedBySlot, SORT_NUMERIC);
        $this->consumedBySlot = $consumedBySlot;
        $this->outputs = $outputs;
    }
}
