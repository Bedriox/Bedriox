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

/** Authoritative furnace-family inventory and progress projection for active viewers. */
final readonly class FurnaceUpdated implements WorldEvent
{
    /** @var list<ContainerViewerProjection> */
    public array $viewers;

    /** @var list<int> */
    public array $changedSlots;

    /**
     * @param array<mixed> $viewers
     * @param array<mixed> $changedSlots
     */
    public function __construct(
        array $viewers,
        array $changedSlots,
        public int $cookTime,
        public int $burnTime,
        public int $burnDuration,
        public int $storedExperienceMilli,
    ) {
        if ($cookTime < 0 || $burnTime < 0 || $burnDuration < 0 || $storedExperienceMilli < 0) {
            throw new InvalidArgumentException('Furnace projection values cannot be negative.');
        }
        foreach ($viewers as $viewer) {
            if (!$viewer instanceof ContainerViewerProjection || count($viewer->slots) !== 3) {
                throw new InvalidArgumentException('Furnace viewer projection must contain three slots.');
            }
        }
        foreach ($changedSlots as $slot) {
            if (!is_int($slot) || $slot < 0 || $slot >= 3) {
                throw new InvalidArgumentException('Furnace changed slot is invalid.');
            }
        }
        $this->viewers = array_values($viewers);
        $this->changedSlots = array_values($changedSlots);
    }

    public function recipients(): array
    {
        return array_map(static fn(ContainerViewerProjection $viewer): string => $viewer->sessionId, $this->viewers);
    }
}
