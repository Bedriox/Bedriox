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

/** Fast operator-facing check; the transport still performs the authoritative bind. */
final class UdpBindPreflight
{
    public function assertAvailable(string $address, int $port): void
    {
        if (function_exists('socket_create') && function_exists('socket_bind')) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket !== false) {
                $exclusiveAddressUse = self::integerConstant('SO_EXCLUSIVEADDRUSE');
                if ($exclusiveAddressUse !== null) {
                    @socket_set_option($socket, SOL_SOCKET, $exclusiveAddressUse, 1);
                }
                $bound = @socket_bind($socket, $address, $port);
                socket_close($socket);
                if (!$bound) {
                    throw self::unavailable($address, $port);
                }
                return;
            }
        }

        $endpoint = sprintf('udp://%s:%d', $address, $port);
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_server($endpoint, $errorNumber, $errorMessage, STREAM_SERVER_BIND);
        if (!is_resource($socket)) {
            throw self::unavailable($address, $port);
        }
        fclose($socket);
    }

    private static function integerConstant(string $name): ?int
    {
        $value = get_defined_constants()[$name] ?? null;

        return is_int($value) ? $value : null;
    }

    private static function unavailable(string $address, int $port): PortUnavailableException
    {
        return new PortUnavailableException(sprintf(
            'Bedriox cannot bind UDP %s:%d because the address or port is already in use.',
            $address,
            $port,
        ));
    }
}
