<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

final class Data3dCodec
{
    private const int MAX_RECORD_BYTES = 1_000_000;

    public function encode(Data3dRecord $record): string
    {
        $output = $record->heightmap();
        foreach ($record->biomes() as $storage) {
            $palette = $storage->palette();
            $bits = PaletteIndexCodec::bitsForPaletteSize(count($palette));
            $output .= self::writeByte($bits << 1);
            $output .= PaletteIndexCodec::encodeWords($bits, $storage->indexAt(...));
            if ($bits !== 0) {
                $output .= pack('V', count($palette));
            }
            foreach ($palette as $biomeId) {
                $output .= pack('V', $biomeId & 0xffff_ffff);
            }
            if (strlen($output) > self::MAX_RECORD_BYTES) {
                throw new LevelDbStorageException('Encoded Data3D record exceeds its byte limit.');
            }
        }
        return $output;
    }

    public function decode(string $bytes): Data3dRecord
    {
        if (strlen($bytes) < Data3dRecord::HEIGHTMAP_BYTES || strlen($bytes) > self::MAX_RECORD_BYTES) {
            throw new LevelDbStorageException('Data3D record is truncated or oversized.');
        }
        $reader = new LevelDbBinaryReader($bytes);
        $heightmap = $reader->read(Data3dRecord::HEIGHTMAP_BYTES);
        $biomes = [];
        for ($section = 0; $section < Data3dRecord::BIOME_STORAGE_COUNT; ++$section) {
            $header = $reader->byte();
            if (($header & 1) !== 0) {
                throw new LevelDbStorageException('Persistent biome palette has the network-palette flag set.');
            }
            $bits = $header >> 1;
            $indices = PaletteIndexCodec::decodeWords($reader, $bits);
            $paletteSize = $bits === 0 ? 1 : $reader->unsignedLittleEndian32();
            $maximumPaletteSize = $bits === 0 ? 1 : min(PersistentBlockStorage::ENTRY_COUNT, 1 << $bits);
            if ($paletteSize < 1 || $paletteSize > $maximumPaletteSize) {
                throw new LevelDbStorageException('Persistent biome palette length is outside the admitted range.');
            }
            $palette = [];
            for ($i = 0; $i < $paletteSize; ++$i) {
                $palette[] = $reader->unsignedLittleEndian32();
            }
            foreach ($indices as $index) {
                if ($index >= $paletteSize) {
                    throw new LevelDbStorageException('Persistent biome storage references a missing palette entry.');
                }
            }
            $biomes[] = new PersistentBiomeStorage($palette, $indices);
        }
        if (!$reader->atEnd()) {
            throw new LevelDbStorageException('Data3D record contains trailing bytes.');
        }
        return new Data3dRecord($heightmap, $biomes);
    }

    private static function writeByte(int $value): string
    {
        if ($value < 0 || $value > 255) {
            throw new LevelDbStorageException('Data3D byte value is outside the unsigned byte range.');
        }

        return chr($value);
    }
}
