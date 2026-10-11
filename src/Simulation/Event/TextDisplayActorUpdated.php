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

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\World\Position;
use Bedriox\Server\Plugin\Display\DisplayChange;

final readonly class TextDisplayActorUpdated implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public int $textRuntimeId,
        public string $text,
        public Position $position,
        public ?int $previousInteractionRuntimeId,
        public ?int $interactionRuntimeId,
        public ?float $interactionWidth,
        public ?float $interactionHeight,
        public DisplayChange $change,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
