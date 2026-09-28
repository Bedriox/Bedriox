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

namespace Bedriox\Api\Plugin;

interface SourcePluginRegistrar
{
    /** Canonical root that the development provider may inspect. */
    public function pluginsDirectory(): string;

    /**
     * Stages one complete discovery result for admission after PHAR plugins
     * finish enabling.
     *
     * @param list<SourcePluginDefinition> $definitions
     */
    public function register(array $definitions): void;
}
