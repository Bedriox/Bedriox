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

use Bedriox\Data\LittleEndianBlockStateNbtCodec;
use RuntimeException;

final readonly class PersistentSubChunkCodec
{
    public const int WRITE_VERSION = 8;
    private const int MAX_RECORD_BYTES = 2_000_000;

    public function __construct(private LittleEndianBlockStateNbtCodec $blockStateCodec) {}

    /** Mojang v8 is the stable write format; v9 remains accepted on read. */
    public function encode(StoredSubChunk $subChunk): string
    {
        $storages = $subChunk->storages();
        $output = self::writeByte(self::WRITE_VERSION) . self::writeByte(count($storages));
        foreach ($storages as $storage) {
            $palette = $storage->palette();
            $bits = PaletteIndexCodec::bitsForPaletteSize(count($palette));
            $output .= self::writeByte($bits << 1);
            $output .= PaletteIndexCodec::encodeWords($bits, $storage->indexAt(...));
            if ($bits !== 0) {
                $output .= pack('V', count($palette));
            }
            foreach ($palette as $state) {
                $output .= $this->blockStateCodec->encode($state);
            }
            if (strlen($output) > self::MAX_RECORD_BYTES) {
                throw new LevelDbStorageException('Encoded subchunk exceeds its byte limit.');
            }
        }
        return $output;
    }

    public function decode(string $bytes, int $keySectionY): StoredSubChunk
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_RECORD_BYTES) {
            throw new LevelDbStorageException('Subchunk record is empty or oversized.');
        }
        if ($keySectionY < -128 || $keySectionY > 127) {
            throw new LevelDbStorageException('Subchunk key Y must fit a signed byte.');
        }
        $reader = new LevelDbBinaryReader($bytes);
        $version = $reader->byte();
        if ($version !== 8 && $version !== 9) {
            throw new LevelDbStorageException("Unsupported subchunk storage version $version.");
        }
        $storageCount = $reader->byte();
        if ($storageCount < 1 || $storageCount > 16) {
            throw new LevelDbStorageException('Subchunk layer count is outside the admitted range.');
        }
        $sectionY = $version === 9 ? $reader->signedByte() : $keySectionY;
        if ($sectionY !== $keySectionY) {
            throw new LevelDbStorageException('Subchunk key Y differs from its v9 payload Y.');
        }

        $storages = [];
        for ($layer = 0; $layer < $storageCount; ++$layer) {
            $header = $reader->byte();
            if (($header & 1) !== 0) {
                throw new LevelDbStorageException('Persistent block palette has the network-palette flag set.');
            }
            $bits = $header >> 1;
            $indices = PaletteIndexCodec::decodeWords($reader, $bits);
            $paletteSize = $bits === 0 ? 1 : $reader->unsignedLittleEndian32();
            $maximumPaletteSize = $bits === 0 ? 1 : min(PersistentBlockStorage::ENTRY_COUNT, 1 << $bits);
            if ($paletteSize < 1 || $paletteSize > $maximumPaletteSize) {
                throw new LevelDbStorageException('Persistent block palette length is outside the admitted range.');
            }
            $palette = [];
            for ($i = 0; $i < $paletteSize; ++$i) {
                try {
                    $decoded = $this->blockStateCodec->decode($reader->remainingBytes());
                } catch (RuntimeException $error) {
                    throw new LevelDbStorageException('Persistent block palette contains invalid state NBT.', previous: $error);
                }
                $palette[] = $decoded->state();
                $reader->advanceTo($reader->offset() + $decoded->nextOffset());
            }
            foreach ($indices as $index) {
                if ($index >= $paletteSize) {
                    throw new LevelDbStorageException('Persistent block storage references a missing palette entry.');
                }
            }
            $storages[] = new PersistentBlockStorage($palette, $indices);
        }
        if (!$reader->atEnd()) {
            throw new LevelDbStorageException('Subchunk record contains trailing bytes.');
        }
        return new StoredSubChunk($sectionY, $storages, $version);
    }

    private static function writeByte(int $value): string
    {
        if ($value < 0 || $value > 255) {
            throw new LevelDbStorageException('Subchunk byte value is outside the unsigned byte range.');
        }

        return chr($value);
    }
}
