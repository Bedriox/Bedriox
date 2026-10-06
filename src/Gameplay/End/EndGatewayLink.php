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

/** One durable, bidirectional inner/outer End gateway pair. */
final readonly class EndGatewayLink
{
    public function __construct(
        public int $slot,
        public BlockPosition $inner,
        public BlockPosition $outer,
    ) {}

    public function destinationFrom(BlockPosition $gateway): ?BlockPosition
    {
        return match (true) {
            $gateway->equals($this->inner) => $this->outer,
            $gateway->equals($this->outer) => $this->inner,
            default => null,
        };
    }
}
