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

namespace Bedriox\Server\Login;

use Bedriox\RakNet\Protocol\Reliability;
use InvalidArgumentException;

final readonly class OutgoingLoginPayload
{
    public Reliability $reliability;
    public int $orderingChannel;

    public function __construct(public string $payload)
    {
        if ($payload === '') {
            throw new InvalidArgumentException('Outgoing login payload cannot be empty.');
        }
        $this->reliability = Reliability::ReliableOrdered;
        $this->orderingChannel = 0;
    }
}
