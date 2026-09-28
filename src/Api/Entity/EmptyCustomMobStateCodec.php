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

namespace Bedriox\Api\Entity;

use InvalidArgumentException;

final class EmptyCustomMobStateCodec implements CustomMobStateCodec
{
    public function encode(CustomMobBehavior $behavior): CustomEntityState
    {
        return new CustomEntityState(1, '');
    }

    public function restore(CustomMobBehavior $behavior, CustomEntityState $state): void
    {
        if ($state->schemaVersion !== 1 || $state->size() !== 0) {
            throw new InvalidArgumentException('This custom mob does not support persistent plugin state.');
        }
    }
}
