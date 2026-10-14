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

namespace Bedriox\Server\Command\Default;

/** Structure features currently generated and truthfully locatable by Bedriox. */
enum LocateStructure: string
{
    case Village = 'minecraft:village';
    case Mineshaft = 'minecraft:mineshaft';
    case Stronghold = 'minecraft:stronghold';
    case Fortress = 'minecraft:fortress';
    case BastionRemnant = 'minecraft:bastion_remnant';
    case EndCity = 'minecraft:end_city';

    public function locatorName(): string
    {
        return substr($this->value, strlen('minecraft:'));
    }
}
