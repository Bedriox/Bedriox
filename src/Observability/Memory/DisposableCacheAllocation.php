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

namespace Bedriox\Server\Observability\Memory;

use InvalidArgumentException;

/** A rebuildable cache plus its current runtime retention priority. */
final readonly class DisposableCacheAllocation
{
    public function __construct(
        public DisposableByteCache $cache,
        public int $priority = 0,
    ) {
        if ($priority < 0 || $priority > 1_000) {
            throw new InvalidArgumentException('Disposable cache priority must be between 0 and 1000.');
        }
    }
}
