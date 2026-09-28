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

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandJobSubscription;

final readonly class OwnedCommandJobSubscription implements CommandJobSubscription
{
    public function __construct(private CommandRegistry $registry, private int $id) {}

    public function cancel(): void
    {
        $this->registry->cancelJob($this->id);
    }

    public function isRunning(): bool
    {
        return $this->registry->hasJob($this->id);
    }
}
