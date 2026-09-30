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

namespace Bedriox\Api\Whitelist;

interface Whitelist
{
    public function isEnabled(): bool;
    public function setEnabled(bool $enabled): bool;
    public function contains(string $name, ?string $uuid = null): bool;
    public function add(string $name, ?string $uuid = null): bool;
    public function remove(string $name): bool;
    /** @return list<WhitelistEntry> */
    public function entries(): array;
    public function reload(): void;
}
