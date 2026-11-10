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

namespace Bedriox\Api\Form\Component;

use InvalidArgumentException;

final class ComponentSupport
{
    public static function id(string $id): void
    {
        if (preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Component ID is invalid.');
        }
    }
    public static function text(string $value, int $max = 2_048, bool $empty = false): void
    {
        if ((!$empty && $value === '') || strlen($value) > $max || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Component text is invalid.');
        }
    }
    private function __construct() {}
}
