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

namespace Bedriox\Server\World\Storage\LevelDb;

/** Minimal binary-safe LevelDB boundary used by the world provider. */
interface LevelDbDatabase
{
    public function get(string $key): ?string;

    /**
     * @param array<string, string> $puts
     * @param list<string>          $deletes
     */
    public function writeBatch(array $puts, array $deletes): void;

    /** Repeated calls must be harmless. */
    public function close(): void;
}
