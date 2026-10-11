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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\Value\EmoteId;

/** @internal One bounded transient animation intent consumed by actor projection. */
final readonly class ActorAnimationRequest
{
    private function __construct(
        public bool $armSwing,
        public ?EmoteId $emote,
    ) {}

    public static function armSwing(): self
    {
        return new self(true, null);
    }

    public static function emote(EmoteId $emote): self
    {
        return new self(false, $emote);
    }
}
