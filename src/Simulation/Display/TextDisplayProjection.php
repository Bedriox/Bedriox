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

namespace Bedriox\Server\Simulation\Display;

use Bedriox\Api\Display\TextDisplay;

/** @internal Stable wire identities and audience for one plugin text display. */
final class TextDisplayProjection
{
    /** @var array<string, true> */
    public array $viewers = [];

    public ?int $interactionRuntimeId = null;

    public ?string $interactionUniqueId = null;

    public function __construct(
        public readonly TextDisplay $display,
        public readonly int $textRuntimeId,
    ) {}
}
