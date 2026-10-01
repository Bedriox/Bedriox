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

namespace Bedriox\Server\Entity\Mount;

use Bedriox\Api\Entity\MountedPassenger;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Server\Entity\AbstractEntity;

final readonly class MountLink
{
    public function __construct(
        public string $passengerKey,
        public MountedPassenger $passenger,
        public AbstractEntity $vehicle,
        public MountSeat $seat,
        public ?AbstractEntity $passengerEntity = null,
        public ?string $playerSessionId = null,
    ) {}
}
