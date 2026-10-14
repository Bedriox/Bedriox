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

namespace Bedriox\Server\World\Generation\Surface;

/** Semantic surface choice resolved to canonical blocks by the generation composition root. */
enum OverworldSurfaceMaterial
{
    case Grass;
    case CoarseDirt;
    case Podzol;
    case Stone;
    case GravelScree;
    case Sand;
    case GravelShore;
    case ClayBed;
    case MudBed;
}
