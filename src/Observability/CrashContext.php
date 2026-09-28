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

namespace Bedriox\Server\Observability;

use InvalidArgumentException;

final readonly class CrashContext
{
    /** @param list<CrashPlayer> $players */
    public function __construct(
        public int $tick = 0,
        public array $players = [],
        public ?CrashPlayer $involvedPlayer = null,
        public ?string $pluginAttribution = null,
    ) {
        if ($this->tick < 0 || count($this->players) > 1_024) {
            throw new InvalidArgumentException('Crash context exceeds its bounded limits.');
        }
        if ($this->pluginAttribution !== null
            && (strlen($this->pluginAttribution) > 512 || preg_match('/[\x00-\x1f\x7f]/', $this->pluginAttribution) === 1)) {
            throw new InvalidArgumentException('Crash plugin attribution is invalid.');
        }
    }
}
