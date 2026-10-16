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

/** Immutable protocol-independent persona geometry component. */
final readonly class SkinPersonaPiece
{
    public function __construct(
        public string $id,
        public string $type,
        public string $packId,
        public bool $default,
        public string $productId,
    ) {
        self::bounded($id, 4_096, 'Persona piece ID');
        self::bounded($type, 64, 'Persona piece type', false);
        self::bounded($productId, 4_096, 'Persona piece product ID');
        if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $packId) !== 1) {
            throw new InvalidArgumentException('Persona piece pack ID must be a UUID.');
        }
    }

    private static function bounded(string $value, int $maximumBytes, string $field, bool $allowEmpty = true): void
    {
        if ((!$allowEmpty && $value === '') || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException($field . ' must be valid UTF-8 and bounded.');
        }
    }
}
