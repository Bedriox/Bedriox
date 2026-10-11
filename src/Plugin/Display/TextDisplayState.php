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

use Bedriox\Api\Display\TextDisplay;
use Bedriox\Api\World\Position;
use Bedriox\Server\Visibility\MutableVisibility;

/** @internal Mutable display state owned exclusively by DisplayRegistry. */
final class TextDisplayState
{
    /** @var array<string, true> */
    public array $tags = [];
    public MutableVisibility $visibility;
    public bool $removed = false;
    public ?TextDisplay $handle = null;
    public ?InteractionEntityState $interaction = null;

    public function __construct(
        public readonly string $owner,
        public readonly string $ownerKey,
        public readonly string $uniqueId,
        public readonly ?string $key,
        public Position $position,
        public string $text,
        public int $viewDistance,
    ) {}
}
