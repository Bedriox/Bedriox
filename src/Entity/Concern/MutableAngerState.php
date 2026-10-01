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

namespace Bedriox\Server\Entity\Concern;

use Bedriox\Api\Entity\Capability\Angerable;

/** @internal Authoritative mutation boundary for entities using bounded anger state. */
interface MutableAngerState extends Angerable
{
    public const int MAXIMUM_ANGER_TICKS = 24_000;

    public function setAngerTargetUniqueId(?string $targetUniqueId, int $ticks): void;

    public function advanceAngerState(int $ticks = 1): void;
}
