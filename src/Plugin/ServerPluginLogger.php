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

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Plugin\PluginLogger;
use Bedriox\Server\Observability\ServerLogger;

final readonly class ServerPluginLogger implements PluginLogger
{
    public function __construct(
        private string $plugin,
        private ServerLogger $logger,
    ) {}

    public function debug(string $message): void
    {
        $this->logger->debug($message, $this->component());
    }

    public function info(string $message): void
    {
        $this->logger->info($message, $this->component());
    }

    public function warning(string $message): void
    {
        $this->logger->warning($message, $this->component());
    }

    public function error(string $message): void
    {
        $this->logger->error($message, $this->component());
    }

    private function component(): string
    {
        return 'Plugin/' . $this->plugin;
    }
}
