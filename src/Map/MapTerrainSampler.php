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

namespace Bedriox\Server\Map;

/** Supplies one authoritative RGBA map sample without loading terrain. */
interface MapTerrainSampler
{
    /** Returns exactly four RGBA bytes; transparent means the terrain is not currently available. */
    public function colorAt(int $x, int $z, int $scale = 1): string;

    /** Invalidates the one surface column changed by an authoritative block mutation. */
    public function invalidateColumn(int $x, int $z): void;
}
