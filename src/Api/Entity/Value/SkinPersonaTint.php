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

use InvalidArgumentException;

/** Immutable protocol-independent persona tint. */
final readonly class SkinPersonaTint
{
    /** @var list<int> */
    public array $colors;

    /** @param array<int, int> $colors */
    public function __construct(public string $type, array $colors)
    {
        if ($type === '' || strlen($type) > 64 || preg_match('//u', $type) !== 1) {
            throw new InvalidArgumentException('Persona tint type must be valid UTF-8 and bounded.');
        }
        if (!array_is_list($colors) || count($colors) !== 4) {
            throw new InvalidArgumentException('Persona tint must contain exactly four colors.');
        }
        foreach ($colors as $color) {
            if ($color < -2_147_483_648 || $color > 2_147_483_647) {
                throw new InvalidArgumentException('Persona tint color is outside the signed 32-bit range.');
            }
        }
        $this->colors = $colors;
    }
}
