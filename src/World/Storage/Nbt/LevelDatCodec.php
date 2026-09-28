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

use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Exception\UnsupportedWorldDataException;
use InvalidArgumentException;

final readonly class LevelDatCodec
{
    public const int CURRENT_STORAGE_VERSION = 10;
    public const int CURRENT_NETWORK_VERSION = 2193;
    public const int MAX_NBT_BYTES = 4_194_304;
    public const int HEADER_BYTES = 8;

    public function __construct(private LittleEndianNbtCodec $nbt = new LittleEndianNbtCodec()) {}

    public function decode(string $contents): LevelDatMetadata
    {
        if (strlen($contents) < self::HEADER_BYTES) {
            throw new CorruptWorldDataException('Truncated level.dat header.');
        }
        /** @var array{version: int, length: int} $header */
        $header = unpack('Vversion/Vlength', substr($contents, 0, self::HEADER_BYTES));
        if ($header['version'] < 1) {
            throw new CorruptWorldDataException('level.dat header version must be positive.');
        }
        if ($header['version'] > self::CURRENT_STORAGE_VERSION) {
            throw new UnsupportedWorldDataException("level.dat header version {$header['version']} is not supported.");
        }
        if ($header['length'] > self::MAX_NBT_BYTES) {
            throw new CorruptWorldDataException('level.dat NBT payload exceeds the configured size limit.');
        }
        if (strlen($contents) !== self::HEADER_BYTES + $header['length']) {
            throw new CorruptWorldDataException('level.dat payload length does not match its header.');
        }
        $metadata = new LevelDatMetadata(
            $header['version'],
            $this->nbt->decodeRootCompound(substr($contents, self::HEADER_BYTES)),
        );
        $storageVersion = $metadata->storageVersion();
        if ($storageVersion < 1) {
            throw new CorruptWorldDataException("Invalid 'StorageVersion' tag in level.dat.");
        }
        if ($storageVersion > self::CURRENT_STORAGE_VERSION) {
            throw new UnsupportedWorldDataException("LevelDB storage version $storageVersion is not supported.");
        }
        $networkVersion = $metadata->networkVersion();
        if ($networkVersion < 1) {
            throw new CorruptWorldDataException("Invalid 'NetworkVersion' tag in level.dat.");
        }
        if ($networkVersion > self::CURRENT_NETWORK_VERSION) {
            throw new UnsupportedWorldDataException("Network version $networkVersion is not supported.");
        }

        return $metadata;
    }

    public function encode(LevelDatMetadata $metadata): string
    {
        if ($metadata->headerVersion < 1 || $metadata->headerVersion > self::CURRENT_STORAGE_VERSION) {
            throw new InvalidArgumentException('level.dat header version is not supported.');
        }
        $storageVersion = $metadata->storageVersion();
        if ($storageVersion < 1 || $storageVersion > self::CURRENT_STORAGE_VERSION) {
            throw new InvalidArgumentException('level.dat storage version is not supported.');
        }
        $networkVersion = $metadata->networkVersion();
        if ($networkVersion < 1 || $networkVersion > self::CURRENT_NETWORK_VERSION) {
            throw new InvalidArgumentException('level.dat network version is not supported.');
        }
        $payload = $this->nbt->encodeRootCompound($metadata->root);

        return pack('V2', $metadata->headerVersion, strlen($payload)) . $payload;
    }
}
