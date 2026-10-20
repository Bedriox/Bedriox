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

namespace Bedriox\Server\Tests\Security;

use Bedriox\Server\Security\TrustedProxyEndpointPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TrustedProxyEndpointPolicyTest extends TestCase
{
    public function testAllowsOnlyConfiguredObservedNetworks(): void
    {
        $policy = new TrustedProxyEndpointPolicy(['127.0.0.1/32', '10.20.30.0/24']);

        self::assertTrue($policy->allows('127.0.0.1', 19_177));
        self::assertTrue($policy->allows('10.20.30.42', 50_000));
        self::assertFalse($policy->allows('10.20.31.42', 50_000));
        self::assertFalse($policy->allows('invalid', 50_000));
        self::assertFalse($policy->allows('127.0.0.1', 0));
    }

    public function testRejectsMalformedNetworks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrustedProxyEndpointPolicy(['127.0.0.1/33']);
    }
}
