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

namespace Bedriox\Api\Entity\Controller;

use Bedriox\Api\Entity\Skin;
use Bedriox\Api\Entity\Value\EmoteId;

interface HumanController extends MobController
{
    public function setSkin(Skin $skin): void;

    /** Publishes one validated Bedrock emote to the human's admitted viewers. */
    public function playEmote(EmoteId $emote): void;
}
