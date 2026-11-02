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

namespace Bedriox\Server\Transport;

final readonly class SessionInfo
{
    public function __construct(
        public int $id,
        public string $networkId,
        public string $remoteAddress,
        public int $remotePort,
        public ?string $clientAddress = null,
    ) {
        if ($id < 0) {
            throw new \InvalidArgumentException('Transport session ID must be nonnegative.');
        }
        if ($networkId === '') {
            throw new \InvalidArgumentException('Transport network ID must not be empty.');
        }
        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Transport remote address must be an IP address.');
        }
        if ($remotePort < 1 || $remotePort > 65_535) {
            throw new \InvalidArgumentException('Transport remote port is out of range.');
        }
        if ($clientAddress !== null && filter_var($clientAddress, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Transport client address must be an IP address when present.');
        }
    }

    public function effectiveClientAddress(): string
    {
        return $this->clientAddress ?? $this->remoteAddress;
    }
}
