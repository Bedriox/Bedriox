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

namespace Bedriox\Server\Tests\ResourcePack;

use Bedriox\Server\ResourcePack\ResourcePackArchive;
use Bedriox\Server\ResourcePack\ResourcePackTransferGovernor;
use PHPUnit\Framework\TestCase;

final class ResourcePackTransferGovernorTest extends TestCase
{
    public function testAdmissionIsFairAndBoundedAcrossSessions(): void
    {
        $governor = new ResourcePackTransferGovernor(1, 1_048_576, ResourcePackArchive::CHUNK_BYTES, 0);

        self::assertTrue($governor->acquire('first', ResourcePackArchive::CHUNK_BYTES, 0));
        self::assertFalse($governor->acquire('second', ResourcePackArchive::CHUNK_BYTES, 0));
        $governor->complete('first');
        self::assertFalse($governor->acquire('first', ResourcePackArchive::CHUNK_BYTES, 250_000_000));
        self::assertTrue($governor->acquire('second', ResourcePackArchive::CHUNK_BYTES, 250_000_000));

        self::assertSame(1, $governor->snapshot()['in_flight']);
    }

    public function testCancelledWaiterDoesNotBlockNextSession(): void
    {
        $governor = new ResourcePackTransferGovernor(1, 1_048_576, ResourcePackArchive::CHUNK_BYTES, 0);
        self::assertTrue($governor->acquire('active', ResourcePackArchive::CHUNK_BYTES, 0));
        self::assertFalse($governor->acquire('cancelled', ResourcePackArchive::CHUNK_BYTES, 0));
        self::assertFalse($governor->acquire('next', ResourcePackArchive::CHUNK_BYTES, 0));
        $governor->cancel('cancelled');
        $governor->complete('active');

        self::assertTrue($governor->acquire('next', ResourcePackArchive::CHUNK_BYTES, 250_000_000));
    }
}
