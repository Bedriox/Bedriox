<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Biome;
use Bedriox\Server\World\BiomeStorage;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkFinalizationState;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use Bedriox\Server\World\SubChunk;
use Bedriox\Server\World\SubChunkBlockStorage;

/** Canonical, checksummed chunk snapshot codec for process-isolated worker transfers. */
final class ChunkTransferCodec
{
    public const int MAXIMUM_ENCODED_BYTES = 16_777_216;
    private const string MAGIC = "BXCT\x00\x01";
    private const int CHECKSUM_BYTES = 32;
    private const int MAXIMUM_STATE_BYTES = 65_535;

    public function encode(Chunk $chunk, BlockStateRegistry $states): string
    {
        $body = pack('N2', $chunk->position->x, $chunk->position->z)
            . self::encodeDecimal($chunk->revision)
            . self::encodeDecimal($chunk->persistedRevision)
            . self::encodeByte($chunk->dirtyFlags)
            . self::encodeByte($chunk->finalizationState->value)
            . self::encodeString($chunk->biome()->identifier)
            . self::encodeState($states->state($chunk->airState()));
        $sections = $chunk->populatedSections();
        $body .= self::encodeByte(count($sections));
        foreach ($sections as $section) {
            $layers = $section->blockStorageLayers();
            $body .= pack('cC', $section->sectionY, count($layers));
            foreach ($layers as $storage) {
                $palette = $storage->palette();
                $body .= pack('n', count($palette));
                foreach ($palette as $internalId) {
                    $body .= self::encodeState($states->state($internalId));
                }
                $body .= $storage->paletteIndices();
                self::guardBody($body);
            }
        }
        $biomes = $chunk->biomeStorages();
        $body .= self::encodeByte(count($biomes));
        foreach ($biomes as $sectionY => $storage) {
            $palette = $storage->palette();
            $body .= pack('cn', $sectionY, count($palette));
            foreach ($palette as $biome) {
                $body .= self::encodeString($biome->identifier);
            }
            $body .= $storage->paletteIndices();
            self::guardBody($body);
        }
        $blockEntities = (new PersistentBlockEntityCodec())->encode($chunk->blockEntityCollection());
        $body .= pack('N', strlen($blockEntities)) . $blockEntities;
        self::guardBody($body);

        $encoded = self::MAGIC . pack('N', strlen($body)) . $body . hash('sha256', $body, true);
        if (strlen($encoded) > self::MAXIMUM_ENCODED_BYTES) {
            throw new ChunkTransferException('Encoded chunk transfer exceeds its byte limit.');
        }

        return $encoded;
    }

    public function decode(string $encoded, BlockStateRegistry $states): Chunk
    {
        if (strlen($encoded) < strlen(self::MAGIC) + 4 + self::CHECKSUM_BYTES
            || strlen($encoded) > self::MAXIMUM_ENCODED_BYTES
            || substr($encoded, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new ChunkTransferException('Chunk transfer header or encoded size is invalid.');
        }
        $length = unpack('Nvalue', substr($encoded, strlen(self::MAGIC), 4));
        $bodyLength = is_array($length) ? ($length['value'] ?? null) : null;
        $headerBytes = strlen(self::MAGIC) + 4;
        if (!is_int($bodyLength) || $bodyLength < 1
            || $headerBytes + $bodyLength + self::CHECKSUM_BYTES !== strlen($encoded)) {
            throw new ChunkTransferException('Chunk transfer body length is invalid.');
        }
        $body = substr($encoded, $headerBytes, $bodyLength);
        $checksum = substr($encoded, $headerBytes + $bodyLength);
        if (!hash_equals(hash('sha256', $body, true), $checksum)) {
            throw new ChunkTransferException('Chunk transfer checksum does not match.');
        }

        try {
            $reader = new ChunkTransferReader($body);
            $position = new ChunkPosition($reader->signedInt(), $reader->signedInt());
            $revision = self::decodeDecimal($reader);
            $persistedRevision = self::decodeDecimal($reader);
            $dirtyFlags = $reader->byte();
            $finalization = ChunkFinalizationState::tryFrom($reader->byte())
                ?? throw new ChunkTransferException('Chunk transfer finalization state is unknown.');
            $biome = new Biome($reader->boundedString(128));
            $air = $states->internalId(self::decodeState($reader));
            $sectionCount = $reader->byte();
            if ($sectionCount > Chunk::SECTION_COUNT) {
                throw new ChunkTransferException('Chunk transfer contains too many sections.');
            }
            $sections = [];
            for ($sectionIndex = 0; $sectionIndex < $sectionCount; ++$sectionIndex) {
                $sectionY = $reader->signedByte();
                $layerCount = $reader->byte();
                if ($layerCount < 1) {
                    throw new ChunkTransferException('Chunk transfer section contains no block layers.');
                }
                $layers = [];
                for ($layer = 0; $layer < $layerCount; ++$layer) {
                    $layers[] = self::decodeBlockStorage($reader, $states);
                }
                $sections[] = SubChunk::fromBlockStorageLayers($sectionY, $layers);
            }
            $biomeCount = $reader->byte();
            if ($biomeCount !== Chunk::SECTION_COUNT) {
                throw new ChunkTransferException('Chunk transfer must contain every biome section.');
            }
            $biomeStorages = [];
            for ($index = 0; $index < $biomeCount; ++$index) {
                $sectionY = $reader->signedByte();
                $paletteCount = $reader->unsignedShort();
                if ($paletteCount < 1 || $paletteCount > 256 || isset($biomeStorages[$sectionY])) {
                    throw new ChunkTransferException('Chunk transfer biome palette or section is invalid.');
                }
                $palette = [];
                for ($paletteIndex = 0; $paletteIndex < $paletteCount; ++$paletteIndex) {
                    $palette[] = new Biome($reader->boundedString(128));
                }
                $biomeStorages[$sectionY] = BiomeStorage::fromPaletteIndices(
                    $palette,
                    $reader->bytes(BiomeStorage::BIOME_COUNT),
                );
            }
            $blockEntityLength = $reader->signedInt();
            if ($blockEntityLength < 0 || $blockEntityLength > PersistentBlockEntityCodec::MAXIMUM_BYTES) {
                throw new ChunkTransferException('Chunk transfer block-entity length is outside its bound.');
            }
            $blockEntities = (new PersistentBlockEntityCodec())->decode(
                $reader->bytes($blockEntityLength),
                $position,
            );
            $reader->finish();

            return new Chunk(
                $position,
                $air,
                $sections,
                $biome,
                $revision,
                $persistedRevision,
                $dirtyFlags,
                $finalization,
                $biomeStorages,
                $blockEntities,
            );
        } catch (ChunkTransferException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ChunkTransferException('Chunk transfer contains invalid canonical chunk data.', previous: $error);
        }
    }

    private static function decodeBlockStorage(ChunkTransferReader $reader, BlockStateRegistry $states): SubChunkBlockStorage
    {
        $paletteCount = $reader->unsignedShort();
        if ($paletteCount < 1 || $paletteCount > 256) {
            throw new ChunkTransferException('Chunk transfer block palette size is invalid.');
        }
        $palette = [];
        for ($index = 0; $index < $paletteCount; ++$index) {
            $palette[] = $states->internalId(self::decodeState($reader));
        }

        return SubChunkBlockStorage::fromPaletteIndices($palette, $reader->bytes(SubChunkBlockStorage::BLOCK_COUNT));
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
            throw new ChunkTransferException('Chunk transfer canonical block state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== 2
            || !is_string($decoded[0]) || !is_array($decoded[1]) || count($decoded[1]) > 128) {
            throw new ChunkTransferException('Chunk transfer canonical block state has an invalid shape.');
        }

        return CanonicalBlockState::from($decoded[0], $decoded[1]);
    }

    private static function encodeString(string $value): string
    {
        $length = strlen($value);
        if ($length < 1 || $length > 65_535) {
            throw new ChunkTransferException('Chunk transfer string length is outside its bound.');
        }

        return pack('n', $length) . $value;
    }

    private static function encodeDecimal(int $value): string
    {
        if ($value < 0) {
            throw new ChunkTransferException('Chunk transfer revision cannot be negative.');
        }

        return self::encodeString((string) $value);
    }

    private static function encodeByte(int $value): string
    {
        if ($value < 0 || $value > 255) {
            throw new ChunkTransferException('Chunk transfer byte value is outside its bound.');
        }

        return pack('C', $value);
    }

    private static function decodeDecimal(ChunkTransferReader $reader): int
    {
        $value = $reader->boundedString(20);
        if (preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) !== 1) {
            throw new ChunkTransferException('Chunk transfer revision is malformed.');
        }
        $decoded = (int) $value;
        if ((string) $decoded !== $value) {
            throw new ChunkTransferException('Chunk transfer revision is outside the platform integer range.');
        }

        return $decoded;
    }

    private static function guardBody(string $body): void
    {
        if (strlen($body) > self::MAXIMUM_ENCODED_BYTES - 64) {
            throw new ChunkTransferException('Encoded chunk transfer exceeds its byte limit.');
        }
    }
}
