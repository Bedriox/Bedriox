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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\PortUnavailableException;
use Bedriox\Server\Runtime\UdpBindPreflight;
use PHPUnit\Framework\TestCase;

final class UdpBindPreflightTest extends TestCase
{
    public function testRejectsAnOccupiedUdpPortWithOperatorFacingContext(): void
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertNotFalse($socket);
        if (defined('SO_EXCLUSIVEADDRUSE')) {
            $exclusiveAddressUse = constant('SO_EXCLUSIVEADDRUSE');
            self::assertTrue(socket_set_option($socket, SOL_SOCKET, $exclusiveAddressUse, 1));
        }
        self::assertTrue(socket_bind($socket, '127.0.0.1', 0));
        $address = null;
        $port = null;
        self::assertTrue(socket_getsockname($socket, $address, $port));
        self::assertIsInt($port);

        try {
            (new UdpBindPreflight())->assertAvailable('127.0.0.1', $port);
            self::fail('Expected the occupied UDP port to be rejected.');
        } catch (PortUnavailableException $exception) {
            self::assertStringContainsString("127.0.0.1:{$port}", $exception->getMessage());
            self::assertStringContainsString('already in use', $exception->getMessage());
        } finally {
            socket_close($socket);
        }
    }
}
