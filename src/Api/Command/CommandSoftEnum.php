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

namespace Bedriox\Api\Command;

/**
 * A named command suggestion set whose values may change while the server is running.
 *
 * Instances are created by CommandRegistrar::registerSoftEnum().
 */
interface CommandSoftEnum
{
    public function name(): string;

    /** @return list<string> */
    public function values(): array;

    /** @param list<string> $values */
    public function replace(array $values): bool;

    public function add(string $value): bool;

    public function remove(string $value): bool;

    public function isRegistered(): bool;
}
