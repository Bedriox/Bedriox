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

namespace Bedriox\Server\World\Storage\LevelDb;

/** @internal */
final class LevelDbBinaryReader
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    public function byte(): int
    {
        return ord($this->read(1));
    }

    public function signedByte(): int
    {
        $value = $this->byte();
        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    public function unsignedLittleEndian32(): int
    {
        $value = unpack('Vvalue', $this->read(4))['value'] ?? null;
        if (!is_int($value)) {
            throw new LevelDbStorageException('LevelDB unsigned integer cannot be decoded.');
        }
        return $value;
    }

    public function read(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->bytes)) {
            throw new LevelDbStorageException('LevelDB record is truncated.');
        }
        $result = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;
        return $result;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function remainingBytes(): string
    {
        return substr($this->bytes, $this->offset);
    }

    public function advanceTo(int $offset): void
    {
        if ($offset < $this->offset || $offset > strlen($this->bytes)) {
            throw new LevelDbStorageException('LevelDB decoder produced an invalid offset.');
        }
        $this->offset = $offset;
    }

    public function atEnd(): bool
    {
        return $this->offset === strlen($this->bytes);
    }
}
