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

namespace Bedriox\Api\ResourcePack;

final readonly class ResourcePackDefinition
{
    public function __construct(
        public string $archivePath,
        public string $subPackName = '',
        public bool $required = false,
    ) {
        if ($archivePath === '' || strlen($archivePath) > 4_096 || str_contains($archivePath, "\0")) {
            throw new \InvalidArgumentException('Resource-pack archive path is invalid.');
        }
        if (strlen($subPackName) > 128 || str_contains($subPackName, "\0")) {
            throw new \InvalidArgumentException('Resource-pack subpack name is invalid.');
        }
    }
}
