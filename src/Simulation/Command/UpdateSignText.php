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

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\World\BlockEntity\SignText;
use Bedriox\Server\World\BlockPosition;

/** Version-independent client intent containing both bounded sign sides. */
final readonly class UpdateSignText implements WorldCommand
{
    public function __construct(
        public string $session,
        public BlockPosition $position,
        public SignText $front,
        public SignText $back,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 64 + strlen($this->session) + strlen($this->front->blob()) + strlen($this->back->blob());
    }
}
