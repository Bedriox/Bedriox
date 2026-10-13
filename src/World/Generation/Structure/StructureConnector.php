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

namespace Bedriox\Server\World\Generation\Structure;

use InvalidArgumentException;

final readonly class StructureConnector
{
    public function __construct(
        public string $key,
        public string $kind,
        public StructureCoordinate $coordinate,
        public StructureDirection $direction,
    ) {
        if (strlen($key) > 64 || strlen($kind) > 64
            || preg_match('/^[a-z0-9_.-]+$/D', $key) !== 1
            || preg_match('/^[a-z0-9_.-]+$/D', $kind) !== 1) {
            throw new InvalidArgumentException('Structure connector keys and kinds must use bounded lowercase identifiers.');
        }
    }
}
