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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\World\BlockPosition;

final readonly class EndEncounterTickResult
{
    /** @param list<EnderDragonAttack> $attacks */
    public function __construct(
        public bool $stateChanged = false,
        public bool $dragonSpawned = false,
        public bool $completed = false,
        public bool $gatewayCreated = false,
        public bool $healed = false,
        public array $attacks = [],
        public ?BlockPosition $gatewayPosition = null,
    ) {}
}
