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

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;

/** @internal Immutable initialization packet whose clear Bedrock batch is reusable for this server boot. */
final readonly class ReusablePlayPacket
{
    public function __construct(
        public string $key,
        public Packet $packet,
    ) {
        if ($key === '' || strlen($key) > 128) {
            throw new \InvalidArgumentException('Reusable play packet key is invalid.');
        }
    }
}
