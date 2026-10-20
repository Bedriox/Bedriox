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

namespace Bedriox\Api\Gateway;

enum GatewayPluginMessageRejectionReason: string
{
    case GATEWAY_DISABLED = 'gateway_disabled';
    case GATEWAY_DISCONNECTED = 'gateway_disconnected';
    case CHANNEL_UNREGISTERED = 'channel_unregistered';
    case PAYLOAD_TOO_LARGE = 'payload_too_large';
    case DELIVERY_FAILED = 'delivery_failed';
    case REMOTE_REJECTED = 'remote_rejected';
}
