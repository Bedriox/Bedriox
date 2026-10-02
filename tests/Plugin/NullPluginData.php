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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Plugin\Data\Configuration;
use Bedriox\Api\Plugin\Data\PluginData;

final readonly class NullPluginData implements PluginData
{
    public function __construct(private string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function hasResource(string $name): bool
    {
        return false;
    }

    public function readResource(string $name): string
    {
        throw new \LogicException('Test plugin data has no resources.');
    }

    public function saveResource(string $name, bool $replace = false): bool
    {
        return false;
    }

    public function saveResources(bool $replace = false): int
    {
        return 0;
    }

    public function config(string $name = 'config.yml'): Configuration
    {
        throw new \LogicException('Test plugin data has no configuration.');
    }
}
