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

namespace Bedriox\Api\Form;

use Bedriox\Api\Player\Player;

interface Form
{
    public function toJson(): string;

    /** @internal The runtime invokes this exactly once after validating the active request. */
    public function submit(Player $player, mixed $response): void;

    /** @internal The runtime invokes this exactly once when the active request closes. */
    public function cancel(Player $player, FormCancelReason $reason): void;
}
