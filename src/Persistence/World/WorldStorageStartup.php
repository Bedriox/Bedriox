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

namespace Bedriox\Server\Persistence\World;

use Bedriox\Server\World\Provider\WorldData;
use InvalidArgumentException;

final readonly class WorldStorageStartup
{
    public function __construct(
        public string $mode,
        public string $path,
        public ?WorldData $createData = null,
        public ?int $createdAt = null,
    ) {
        if (!in_array($mode, ['open', 'create'], true) || $path === '' || strlen($path) > 4_096
            || str_contains($path, "\0") || (($mode === 'create') !== ($createData !== null && $createdAt !== null))
            || ($createdAt !== null && $createdAt < 0)) {
            throw new InvalidArgumentException('World storage startup value is invalid.');
        }
    }
}
