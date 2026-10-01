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

namespace Bedriox\Api\Entity;

use Bedriox\Api\Entity\Value\MountedPassengerKind;
use Bedriox\Api\Entity\Value\MountSeat;
use InvalidArgumentException;

/** Immutable public view of one passenger attached to an entity. */
final readonly class MountedPassenger
{
    public function __construct(
        public MountedPassengerKind $kind,
        public string $uniqueId,
        public int $runtimeId,
        public MountSeat $seat,
    ) {
        if ($uniqueId === '' || strlen($uniqueId) > 128 || preg_match('//u', $uniqueId) !== 1
            || $runtimeId < 1 || $runtimeId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('Mounted passenger identity is invalid or unbounded.');
        }
    }
}
