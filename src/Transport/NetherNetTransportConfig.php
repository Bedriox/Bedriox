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

final readonly class NetherNetTransportConfig
{
    /**
     * @param list<string> $advertisedAddresses
     * @param list<string> $trustedProxyCidrs
     */
    public function __construct(
        public string $bindAddress,
        public int $port,
        public int $lanPort,
        public int $networkId,
        public string $identityPath,
        public string $serverName,
        public int $protocol,
        public string $gameVersion,
        public string $levelName,
        public int $maximumPlayers,
        public bool $acceptsOnlineAuthentication,
        public bool $acceptsSelfSignedAuthentication,
        public int $maximumSessions,
        public int $maximumSessionEvents = 1_024,
        public int $maximumReceivedPayloads = 65_535,
        public int $maximumReceivedPayloadBytes = 67_108_864,
        public int $maximumSignalingConnections = 256,
        public int $maximumNegotiations = 256,
        public int $maximumNegotiationsPerAddress = 8,
        public int $maximumRemoteCandidates = 64,
        public float $gatheringTimeoutSeconds = 15.0,
        public float $channelTimeoutSeconds = 5.0,
        public array $advertisedAddresses = [],
        public array $trustedProxyCidrs = [],
    ) {
        if (filter_var($bindAddress, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('NetherNet bind address must be an IP address.');
        }
        if ($port < 1 || $port > 65_535 || $lanPort < 0 || $lanPort > 65_535) {
            throw new \InvalidArgumentException('NetherNet port is out of range.');
        }
        if ($networkId === 0 || $identityPath === '') {
            throw new \InvalidArgumentException('NetherNet identity configuration is invalid.');
        }
        foreach ($advertisedAddresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('NetherNet advertised address must be an IP address.');
            }
        }
        if ($maximumSessions < 1 || $maximumSessionEvents < 1 || $maximumReceivedPayloads < 1
            || $maximumReceivedPayloadBytes < 1 || $maximumSignalingConnections < 1
            || $maximumNegotiations < 1 || $maximumNegotiationsPerAddress < 1
            || $maximumNegotiationsPerAddress > $maximumNegotiations || $maximumRemoteCandidates < 1
            || $gatheringTimeoutSeconds <= 0.0 || $channelTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('NetherNet transport limits are invalid.');
        }
    }
}
