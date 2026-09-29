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

namespace Bedriox\Server\Simulation\Event;

use InvalidArgumentException;

/** Authoritative brewing inventory and progress projection for every active viewer. */
final readonly class BrewingStandUpdated implements WorldEvent
{
    /**
     * @param list<ContainerViewerProjection> $viewers
     * @param list<int> $changedSlots
     */
    public function __construct(
        public array $viewers,
        public array $changedSlots,
        public int $brewTime,
        public int $fuelAmount,
        public int $fuelTotal,
    ) {
        if ($viewers === [] || $brewTime < 0 || $brewTime > 400
            || $fuelAmount < 0 || $fuelAmount > 32_767
            || $fuelTotal < 0 || $fuelTotal > 32_767 || $fuelAmount > $fuelTotal) {
            throw new InvalidArgumentException('Brewing-stand projection is invalid.');
        }
        foreach ($viewers as $viewer) {
            if (count($viewer->slots) !== 5) {
                throw new InvalidArgumentException('Brewing-stand viewer projection must contain five slots.');
            }
        }
        foreach ($changedSlots as $slot) {
            if ($slot < 0 || $slot >= 5) {
                throw new InvalidArgumentException('Brewing-stand changed slot is invalid.');
            }
        }
    }

    public function recipients(): array
    {
        return array_map(static fn(ContainerViewerProjection $viewer): string => $viewer->sessionId, $this->viewers);
    }
}
