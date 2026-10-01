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

namespace Bedriox\Api\Entity\Value;

enum ArmadilloState: string
{
    case UNROLLED = 'unrolled';
    case ROLLED_UP = 'rolled_up';
    case ROLLED_UP_PEEKING = 'rolled_up_peeking';
    case ROLLED_UP_RELAXING = 'rolled_up_relaxing';
    case ROLLED_UP_UNROLLING = 'rolled_up_unrolling';
}
