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

namespace Bedriox\Server\Security;

use InvalidArgumentException;

final readonly class TrustedProxyEndpointPolicy
{
    /** @var list<array{network: int, mask: int}> */
    private array $networks;

    /** @param list<string> $cidrs */
    public function __construct(array $cidrs)
    {
        if ($cidrs === []) {
            throw new InvalidArgumentException('At least one trusted proxy CIDR is required.');
        }
        $networks = [];
        foreach ($cidrs as $cidr) {
            [$network, $prefix] = self::parse($cidr);
            $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
            $networks[] = ['network' => $network & $mask, 'mask' => $mask];
        }
        $this->networks = $networks;
    }

    public function allows(string $remoteAddress, int $remotePort): bool
    {
        $candidate = ip2long($remoteAddress);
        if ($candidate === false || $remotePort < 1 || $remotePort > 65_535) {
            return false;
        }
        foreach ($this->networks as $network) {
            if (($candidate & $network['mask']) === $network['network']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{int, int} */
    private static function parse(string $cidr): array
    {
        $parts = explode('/', $cidr, 2);
        $prefix = count($parts) === 2 && ctype_digit($parts[1]) ? (int) $parts[1] : null;
        $network = ip2long($parts[0]);
        if ($network === false || $prefix === null || $prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('Trusted proxy CIDR is invalid.');
        }

        return [$network, $prefix];
    }
}
