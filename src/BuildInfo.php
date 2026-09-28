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

namespace Bedriox\Server;

use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Plugin\PluginPackageLoader;

final readonly class BuildInfo
{
    public function __construct(
        public string $productName,
        public string $serverVersion,
        public string $gameVersion,
        public int $protocolVersion,
        public string $pluginApiVersion,
        public string $phpVersion,
    ) {}

    public static function current(): self
    {
        return new self(
            Bedriox::NAME,
            Bedriox::VERSION,
            ProtocolVersion::GAME_VERSION,
            ProtocolVersion::CURRENT,
            PluginPackageLoader::API_VERSION,
            PHP_VERSION,
        );
    }

    public function displayName(): string
    {
        return $this->productName . ' ' . $this->serverVersion;
    }

    /** @return list<string> */
    public function publicSummary(): array
    {
        return [
            $this->displayName(),
            'Minecraft: Bedrock ' . $this->gameVersion,
            'Protocol: ' . $this->protocolVersion,
            'PHP: ' . $this->phpVersion,
            'Plugin API: ' . $this->pluginApiVersion,
        ];
    }
}
