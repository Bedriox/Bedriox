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

namespace Bedriox\Server\Effect;

use Bedriox\Api\Effect\EffectInstance;

/** Bounded internal save representation; hidden fallbacks are never exposed through the plugin API. */
final readonly class ActiveEffectPersistenceState
{
    /**
     * @param array<string, EffectInstance>       $active
     * @param array<string, list<EffectInstance>> $hidden
     * @param array<string, int>                  $infiniteElapsedTicks
     */
    public function __construct(
        public array $active,
        public array $hidden = [],
        public array $infiniteElapsedTicks = [],
    ) {}
}
