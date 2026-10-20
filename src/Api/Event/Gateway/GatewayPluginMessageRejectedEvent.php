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

namespace Bedriox\Api\Event\Gateway;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Gateway\GatewayPluginChannel;
use Bedriox\Api\Gateway\GatewayPluginMessageRejectionReason;

final class GatewayPluginMessageRejectedEvent extends Event implements PostEvent
{
    public function __construct(
        private readonly GatewayPluginChannel $channel,
        private readonly GatewayPluginMessageRejectionReason $reason,
        private readonly string $detail = '',
    ) {}

    public function getChannel(): GatewayPluginChannel
    {
        return $this->channel;
    }

    public function getReason(): GatewayPluginMessageRejectionReason
    {
        return $this->reason;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }
}
