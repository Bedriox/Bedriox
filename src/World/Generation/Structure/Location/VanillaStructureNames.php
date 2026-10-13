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

namespace Bedriox\Server\World\Generation\Structure\Location;

/** Public names for structures intentionally owned by the built-in generator. */
final class VanillaStructureNames
{
    /** @return non-empty-list<string> */
    public static function all(): array
    {
        return [
            'bastionremnant', 'endcity', 'fortress', 'stronghold', 'village',
        ];
    }

    private function __construct() {}
}
