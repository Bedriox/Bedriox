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

use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** A bounded combat target snapshot supplied by the authoritative player runtime. */
final readonly class EndEncounterTarget
{
    public function __construct(
        public string $uuid,
        public Position $position,
    ) {
        if ($uuid === '' || strlen($uuid) > 64 || preg_match('//u', $uuid) !== 1) {
            throw new InvalidArgumentException('End encounter target identity is invalid.');
        }
    }
}
