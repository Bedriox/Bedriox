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

namespace Bedriox\Server\Simulation\Encounter;

use Bedriox\Api\Encounter\EnderDragonEncounterController;
use Bedriox\Api\Encounter\EnderDragonPhase;
use Closure;

/** @internal Guarded controller over the authoritative End encounter. */
final readonly class SimulationEnderDragonEncounterController implements EnderDragonEncounterController
{
    /** @param Closure(EnderDragonPhase|null): bool $request */
    public function __construct(private Closure $request) {}

    public function requestRespawn(): bool
    {
        return ($this->request)(null);
    }

    public function requestPhase(EnderDragonPhase $phase): bool
    {
        return ($this->request)($phase);
    }
}
