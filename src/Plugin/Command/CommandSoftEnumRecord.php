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

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandSoftEnum;

/** @internal */
final class CommandSoftEnumRecord
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public readonly int $id,
        public readonly string $owner,
        public readonly bool $pluginOwned,
        public readonly string $name,
        public array $values,
        public readonly CommandSoftEnum $handle,
    ) {}
}
