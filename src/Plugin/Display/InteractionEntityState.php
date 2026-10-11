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

namespace Bedriox\Server\Plugin\Display;

use Bedriox\Api\Display\InteractionEntity;
use Bedriox\Api\World\Position;

/** @internal Mutable interaction state owned exclusively by DisplayRegistry. */
final class InteractionEntityState
{
    public bool $enabled = true;
    public bool $removed = false;
    public ?InteractionEntity $handle = null;

    public function __construct(
        public readonly string $uniqueId,
        public readonly TextDisplayState $display,
        public Position $position,
        public float $width,
        public float $height,
    ) {}
}
