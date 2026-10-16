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
use Bedriox\Server\Simulation\Event\HeldItemChanged;

/** @internal Exposes immutable held-item packets for shared recipient projection. */
interface HeldItemPacketEncoder
{
    /** @return list<Packet> */
    public function heldItemOwnerPackets(HeldItemChanged $event): array;

    public function heldItemEquipmentPacket(HeldItemChanged $event): Packet;
}
