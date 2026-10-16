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

/** Bedrock completion presentation emitted only after an authoritative trade commit. */
final readonly class VillagerTradeCompleted implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(public int $villagerRuntimeId, public array $recipientSessionIds)
    {
        if ($villagerRuntimeId < 1) {
            throw new InvalidArgumentException('Villager trade completion projection is invalid.');
        }
    }

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
