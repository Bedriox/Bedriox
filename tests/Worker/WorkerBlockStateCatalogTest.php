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

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Server\Worker\Runtime\WorkerBlockStateCatalog;
use PHPUnit\Framework\TestCase;

final class WorkerBlockStateCatalogTest extends TestCase
{
    public function testEveryHeavyProjectionIsMaterializedOncePerComputeProcess(): void
    {
        $catalog = new WorkerBlockStateCatalog();

        self::assertSame($catalog->data(), $catalog->data());
        self::assertSame($catalog->networkStates(), $catalog->networkStates());
        self::assertSame($catalog->internalStates(), $catalog->internalStates());
        self::assertSame($catalog->translator(), $catalog->translator());
        self::assertSame($catalog->registryHash(), $catalog->registryHash());
    }
}
