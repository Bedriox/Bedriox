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

namespace Bedriox\Server\World\Storage\Nbt;

use InvalidArgumentException;

final readonly class LittleEndianNbtTag
{
    public const int END = 0;
    public const int BYTE = 1;
    public const int SHORT = 2;
    public const int INT = 3;
    public const int LONG = 4;
    public const int FLOAT = 5;
    public const int DOUBLE = 6;
    public const int BYTE_ARRAY = 7;
    public const int STRING = 8;
    public const int LIST = 9;
    public const int COMPOUND = 10;
    public const int INT_ARRAY = 11;
    public const int LONG_ARRAY = 12;

    /**
     * @param int|float|string|list<LittleEndianNbtTag>|array<string, LittleEndianNbtTag>|list<int> $value
     */
    public function __construct(
        public int $type,
        public int|float|string|array $value,
        public ?int $listType = null,
    ) {
        if ($type < self::BYTE || $type > self::LONG_ARRAY) {
            throw new InvalidArgumentException('NBT tag type is outside the supported range.');
        }
        if ($type === self::LIST && ($listType === null || $listType < self::END || $listType > self::LONG_ARRAY)) {
            throw new InvalidArgumentException('NBT list element type is invalid.');
        }
        if ($type !== self::LIST && $listType !== null) {
            throw new InvalidArgumentException('Only NBT list tags may declare an element type.');
        }
    }

    public static function byte(int $value): self
    {
        return new self(self::BYTE, $value);
    }

    public static function int(int $value): self
    {
        return new self(self::INT, $value);
    }

    public static function long(int $value): self
    {
        return new self(self::LONG, $value);
    }

    public static function float(float $value): self
    {
        return new self(self::FLOAT, $value);
    }

    public static function string(string $value): self
    {
        return new self(self::STRING, $value);
    }

    /** @param list<LittleEndianNbtTag> $value */
    public static function list(int $elementType, array $value): self
    {
        return new self(self::LIST, $value, $elementType);
    }

    /** @param array<string, LittleEndianNbtTag> $value */
    public static function compound(array $value): self
    {
        return new self(self::COMPOUND, $value);
    }
}
