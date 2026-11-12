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

namespace Bedriox\Server\Map;

use Bedriox\Protocol\Packet\ClientboundMapItemDataPacket;
use Bedriox\Protocol\Packet\PacketFrame;

/** One immutable map revision ready for reuse by every viewer. */
final readonly class PreparedMapProjection
{
    public function __construct(
        public int $revision,
        public ClientboundMapItemDataPacket $packet,
        public PacketFrame $frame,
        public string $compressedBatch,
    ) {
        if ($revision < 1 || $compressedBatch === '') {
            throw new \InvalidArgumentException('Prepared map projection is invalid.');
        }
    }

    public function networkBytes(): int
    {
        return strlen($this->compressedBatch);
    }

    /** Approximate owned bytes used for bounded cache admission. */
    public function retainedBytes(): int
    {
        return strlen($this->frame->payload)
            + strlen($this->packet->packedColors->bytes ?? '')
            + strlen($this->compressedBatch);
    }
}
