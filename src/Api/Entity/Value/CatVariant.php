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

enum CatVariant: int
{
    case WHITE = 0;
    case TUXEDO = 1;
    case RED = 2;
    case SIAMESE = 3;
    case BRITISH_SHORTHAIR = 4;
    case CALICO = 5;
    case PERSIAN = 6;
    case RAGDOLL = 7;
    case TABBY = 8;
    case ALL_BLACK = 9;
    case JELLIE = 10;
}
