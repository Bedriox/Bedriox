<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Protocol\Packet\ChunkColumnData;
use Bedriox\Protocol\Packet\ChunkSectionData;
use Bedriox\Protocol\Packet\PackedPalettedStorage;
use Bedriox\Server\World\BiomeRuntimeIdMap;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\PackedPaletteWords;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;

/** Canonical packed snapshot used only for worker-side Bedrock chunk projection. */
final class ChunkProjectionTransferCodec
{
    public const int MAXIMUM_ENCODED_BYTES = 2_097_152;
    private const string MAGIC = "BXPP\x00\x02";
    private const int CHECKSUM_BYTES = 32;
    private const int MAXIMUM_STATE_BYTES = 65_535;

    public function encode(Chunk $chunk, BlockStateRegistry $states): string
    {
        $body = pack('N2', $chunk->position->x, $chunk->position->z)
            . self::encodeDecimal($chunk->revision)
            . self::encodeState($states->state($chunk->airState()));

        $sections = $chunk->populatedSections();
        $body .= self::encodeByte(count($sections));
        foreach ($sections as $section) {
            $storage = $section->blockStorageLayer(0);
            $body .= pack('c', $section->sectionY)
                . self::encodeBlockPalette($storage->palette(), $states)
                . self::encodeByte($storage->networkBitsPerEntry())
                . $storage->networkWordArray();
            self::guardBody($body);
        }

        $biomes = $chunk->biomeStorages();
        $body .= self::encodeByte(count($biomes));
        $previousPaletteBody = null;
        $previousBits = null;
        $previousWords = null;
        foreach ($biomes as $sectionY => $storage) {
            $paletteBody = '';
            foreach ($storage->palette() as $biome) {
                $paletteBody .= self::encodeString($biome->identifier);
            }
            $bits = $storage->networkBitsPerEntry();
            $words = $storage->networkWordArray();
            if ($previousPaletteBody !== null && $paletteBody === $previousPaletteBody
                && $bits === $previousBits && $words === $previousWords) {
                $body .= pack('cC', $sectionY, 0);
            } else {
                $body .= pack('cCn', $sectionY, 1, count($storage->palette()))
                    . $paletteBody
                    . self::encodeByte($bits)
                    . $words;
            }
            $previousPaletteBody = $paletteBody;
            $previousBits = $bits;
            $previousWords = $words;
            self::guardBody($body);
        }

        $blockEntities = (new PersistentBlockEntityCodec())->encodeNetwork($chunk->blockEntityCollection());
        $body .= pack('n', count($blockEntities));
        foreach ($blockEntities as $networkNbt) {
            $body .= pack('N', strlen($networkNbt)) . $networkNbt;
            self::guardBody($body);
        }

        $encoded = self::MAGIC . pack('N', strlen($body)) . $body . hash('sha256', $body, true);
        if (strlen($encoded) > self::MAXIMUM_ENCODED_BYTES) {
            throw new ChunkTransferException('Encoded chunk projection exceeds its byte limit.');
        }

        return $encoded;
    }

    public function decodeColumn(
        string $encoded,
        BlockStateRegistry $states,
        BlockNetworkTranslator $blocks,
        ?BiomeRuntimeIdMap $biomeMap = null,
    ): ChunkColumnData {
        $biomeMap ??= BiomeRuntimeIdMap::bundled();
        $reader = new ChunkTransferReader($this->verifiedBody($encoded));
        try {
            $chunkX = $reader->signedInt();
            $chunkZ = $reader->signedInt();
            self::decodeDecimal($reader);
            $airRuntimeId = $blocks->toNetwork($states->internalId(self::decodeState($reader)));

            $sectionCount = $reader->byte();
            if ($sectionCount > Chunk::SECTION_COUNT) {
                throw new ChunkTransferException('Chunk projection contains too many block sections.');
            }
            $populated = [];
            $highestSectionY = Chunk::MIN_SECTION_Y;
            for ($sectionIndex = 0; $sectionIndex < $sectionCount; ++$sectionIndex) {
                $sectionY = $reader->signedByte();
                if ($sectionY < Chunk::MIN_SECTION_Y || $sectionY > Chunk::MAX_SECTION_Y
                    || isset($populated[$sectionY])) {
                    throw new ChunkTransferException('Chunk projection contains an invalid block section.');
                }
                $paletteCount = $reader->unsignedShort();
                if ($paletteCount < 1 || $paletteCount > 256) {
                    throw new ChunkTransferException('Chunk projection block palette is outside its bound.');
                }
                $palette = [];
                for ($paletteIndex = 0; $paletteIndex < $paletteCount; ++$paletteIndex) {
                    $palette[] = $blocks->toNetwork($states->internalId(self::decodeState($reader)));
                }
                $bits = $reader->byte();
                if ($bits !== PackedPaletteWords::bitsForPaletteSize($paletteCount)) {
                    throw new ChunkTransferException('Chunk projection block palette width is not canonical.');
                }
                $populated[$sectionY] = new ChunkSectionData($sectionY, [new PackedPalettedStorage(
                    $palette,
                    $bits,
                    $reader->bytes(self::wordBytes($bits)),
                    ChunkSectionData::CELL_COUNT,
                )]);
                $highestSectionY = max($highestSectionY, $sectionY);
            }

            $sections = [];
            for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= $highestSectionY; ++$sectionY) {
                $sections[] = $populated[$sectionY]
                    ?? ChunkSectionData::allAir($sectionY, $airRuntimeId);
            }

            $biomeCount = $reader->byte();
            if ($biomeCount !== Chunk::SECTION_COUNT) {
                throw new ChunkTransferException('Chunk projection must contain every biome section.');
            }
            $biomeStorages = [];
            $previousBiome = null;
            for ($offset = 0; $offset < $biomeCount; ++$offset) {
                $sectionY = $reader->signedByte();
                $kind = $reader->byte();
                if ($sectionY !== Chunk::MIN_SECTION_Y + $offset || ($kind !== 0 && $kind !== 1)) {
                    throw new ChunkTransferException('Chunk projection biome section ordering is invalid.');
                }
                if ($kind === 0) {
                    if (!$previousBiome instanceof PackedPalettedStorage) {
                        throw new ChunkTransferException('Chunk projection begins with a repeated biome palette.');
                    }
                    $biomeStorages[] = $previousBiome;
                    continue;
                }
                $paletteCount = $reader->unsignedShort();
                if ($paletteCount < 1 || $paletteCount > 256) {
                    throw new ChunkTransferException('Chunk projection biome palette is outside its bound.');
                }
                $palette = [];
                for ($paletteIndex = 0; $paletteIndex < $paletteCount; ++$paletteIndex) {
                    $identifier = $reader->boundedString(128);
                    $palette[] = $biomeMap->id(new \Bedriox\Server\World\Biome($identifier));
                }
                $bits = $reader->byte();
                if ($bits !== PackedPaletteWords::bitsForPaletteSize($paletteCount, 2)) {
                    throw new ChunkTransferException('Chunk projection biome palette width is not canonical.');
                }
                $previousBiome = new PackedPalettedStorage(
                    $palette,
                    $bits,
                    $reader->bytes(self::wordBytes($bits)),
                    ChunkColumnData::BIOME_CELL_COUNT,
                );
                $biomeStorages[] = $previousBiome;
            }

            $blockEntityCount = $reader->unsignedShort();
            if ($blockEntityCount > \Bedriox\Server\World\BlockEntity\BlockEntityCollection::MAXIMUM_ENTITIES) {
                throw new ChunkTransferException('Chunk projection contains too many block entities.');
            }
            $blockEntities = [];
            $totalBlockEntityBytes = 0;
            for ($index = 0; $index < $blockEntityCount; ++$index) {
                $length = $reader->signedInt();
                if ($length < 3 || $length > PersistentBlockEntityCodec::MAXIMUM_NETWORK_BYTES) {
                    throw new ChunkTransferException('Chunk projection block-entity length is outside its bound.');
                }
                $totalBlockEntityBytes += $length;
                if ($totalBlockEntityBytes > PersistentBlockEntityCodec::MAXIMUM_NETWORK_BYTES) {
                    throw new ChunkTransferException('Chunk projection block-entity data exceeds its byte limit.');
                }
                $networkNbt = $reader->bytes($length);
                if ($networkNbt[0] !== "\x0a") {
                    throw new ChunkTransferException('Chunk projection block entity is not a network-NBT compound.');
                }
                $blockEntities[] = $networkNbt;
            }
            $reader->finish();

            return new ChunkColumnData(
                $chunkX,
                $chunkZ,
                0,
                Chunk::MIN_SECTION_Y,
                Chunk::MAX_SECTION_Y,
                $sections,
                $biomeStorages,
                $blockEntities,
            );
        } catch (ChunkTransferException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ChunkTransferException('Chunk projection contains invalid canonical data.', previous: $error);
        }
    }

    private function verifiedBody(string $encoded): string
    {
        if (strlen($encoded) < strlen(self::MAGIC) + 4 + self::CHECKSUM_BYTES
            || strlen($encoded) > self::MAXIMUM_ENCODED_BYTES
            || substr($encoded, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new ChunkTransferException('Chunk projection header or encoded size is invalid.');
        }
        $decoded = unpack('Nvalue', substr($encoded, strlen(self::MAGIC), 4));
        $bodyLength = is_array($decoded) ? ($decoded['value'] ?? null) : null;
        $headerBytes = strlen(self::MAGIC) + 4;
        if (!is_int($bodyLength) || $bodyLength < 1
            || $headerBytes + $bodyLength + self::CHECKSUM_BYTES !== strlen($encoded)) {
            throw new ChunkTransferException('Chunk projection body length is invalid.');
        }
        $body = substr($encoded, $headerBytes, $bodyLength);
        if (!hash_equals(hash('sha256', $body, true), substr($encoded, $headerBytes + $bodyLength))) {
            throw new ChunkTransferException('Chunk projection checksum does not match.');
        }

        return $body;
    }

    /** @param list<\Bedriox\Server\World\Block\InternalBlockStateId> $palette */
    private static function encodeBlockPalette(array $palette, BlockStateRegistry $states): string
    {
        $output = pack('n', count($palette));
        foreach ($palette as $state) {
            $output .= self::encodeState($states->state($state));
        }

        return $output;
    }

    private static function encodeState(CanonicalBlockState $state): string
    {
        return self::encodeString($state->canonicalKey());
    }

    private static function decodeState(ChunkTransferReader $reader): CanonicalBlockState
    {
        try {
            $decoded = json_decode($reader->boundedString(self::MAXIMUM_STATE_BYTES), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ChunkTransferException('Chunk projection canonical block state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== 2
            || !is_string($decoded[0]) || !is_array($decoded[1]) || count($decoded[1]) > 128) {
            throw new ChunkTransferException('Chunk projection canonical block state has an invalid shape.');
        }

        return CanonicalBlockState::from($decoded[0], $decoded[1]);
    }

    private static function wordBytes(int $bits): int
    {
        if ($bits === 0) {
            return 0;
        }
        $entriesPerWord = intdiv(32, $bits);

        return intdiv(ChunkSectionData::CELL_COUNT + $entriesPerWord - 1, $entriesPerWord) * 4;
    }

    private static function encodeString(string $value): string
    {
        $length = strlen($value);
        if ($length < 1 || $length > 65_535) {
            throw new ChunkTransferException('Chunk projection string length is outside its bound.');
        }

        return pack('n', $length) . $value;
    }

    private static function encodeDecimal(int $value): string
    {
        if ($value < 0) {
            throw new ChunkTransferException('Chunk projection revision cannot be negative.');
        }

        return self::encodeString((string) $value);
    }

    private static function encodeByte(int $value): string
    {
        if ($value < 0 || $value > 255) {
            throw new ChunkTransferException('Chunk projection byte value is outside its bound.');
        }

        return pack('C', $value);
    }

    private static function decodeDecimal(ChunkTransferReader $reader): int
    {
        $value = $reader->boundedString(20);
        if (preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) !== 1) {
            throw new ChunkTransferException('Chunk projection revision is malformed.');
        }
        $decoded = (int) $value;
        if ((string) $decoded !== $value) {
            throw new ChunkTransferException('Chunk projection revision is outside the platform integer range.');
        }

        return $decoded;
    }

    private static function guardBody(string $body): void
    {
        if (strlen($body) > self::MAXIMUM_ENCODED_BYTES - 64) {
            throw new ChunkTransferException('Encoded chunk projection exceeds its byte limit.');
        }
    }
}
