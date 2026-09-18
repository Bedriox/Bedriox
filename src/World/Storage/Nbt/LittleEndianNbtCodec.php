<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\Nbt;

use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use InvalidArgumentException;

final class LittleEndianNbtCodec
{
    public const int MAX_DEPTH = 32;
    public const int MAX_COLLECTION_ENTRIES = 65_536;
    public const int MAX_STRING_BYTES = 65_535;

    private int $offset = 0;
    private int $entries = 0;

    /** @return array<string, LittleEndianNbtTag> */
    public function decodeRootCompound(string $payload): array
    {
        $this->offset = 0;
        $this->entries = 0;
        if ($this->readUnsignedByte($payload) !== LittleEndianNbtTag::COMPOUND) {
            throw new CorruptWorldDataException('level.dat NBT root must be a compound tag.');
        }
        if ($this->readString($payload) !== '') {
            throw new CorruptWorldDataException('level.dat NBT root must be unnamed.');
        }
        $root = $this->readCompound($payload, 1);
        if ($this->offset !== strlen($payload)) {
            throw new CorruptWorldDataException('level.dat NBT payload contains trailing bytes.');
        }

        return $root;
    }

    /** @param array<string, LittleEndianNbtTag> $root */
    public function encodeRootCompound(array $root): string
    {
        $this->entries = 0;
        $payload = chr(LittleEndianNbtTag::COMPOUND) . "\0\0" . $this->writeCompound($root, 1);
        if (strlen($payload) > LevelDatCodec::MAX_NBT_BYTES) {
            throw new InvalidArgumentException('Encoded level.dat NBT exceeds the configured size limit.');
        }

        return $payload;
    }

    /** @return array<string, LittleEndianNbtTag> */
    private function readCompound(string $payload, int $depth): array
    {
        $this->assertDepth($depth);
        $result = [];
        while (true) {
            $type = $this->readUnsignedByte($payload);
            if ($type === LittleEndianNbtTag::END) {
                return $result;
            }
            $this->countEntries(1);
            $name = $this->readString($payload);
            if (array_key_exists($name, $result)) {
                throw new CorruptWorldDataException("Duplicate NBT tag '$name'.");
            }
            $result[$name] = $this->readPayload($payload, $type, $depth + 1);
        }
    }

    private function readPayload(string $payload, int $type, int $depth): LittleEndianNbtTag
    {
        $this->assertDepth($depth);

        return match ($type) {
            LittleEndianNbtTag::BYTE => new LittleEndianNbtTag($type, $this->readSignedByte($payload)),
            LittleEndianNbtTag::SHORT => new LittleEndianNbtTag($type, $this->readSignedShort($payload)),
            LittleEndianNbtTag::INT => new LittleEndianNbtTag($type, $this->readSignedInt($payload)),
            LittleEndianNbtTag::LONG => new LittleEndianNbtTag($type, $this->readSignedLong($payload)),
            LittleEndianNbtTag::FLOAT => new LittleEndianNbtTag($type, $this->readFloat($payload)),
            LittleEndianNbtTag::DOUBLE => new LittleEndianNbtTag($type, $this->readDouble($payload)),
            LittleEndianNbtTag::BYTE_ARRAY => new LittleEndianNbtTag($type, $this->readByteArray($payload)),
            LittleEndianNbtTag::STRING => new LittleEndianNbtTag($type, $this->readString($payload)),
            LittleEndianNbtTag::LIST => $this->readList($payload, $depth),
            LittleEndianNbtTag::COMPOUND => new LittleEndianNbtTag($type, $this->readCompound($payload, $depth)),
            LittleEndianNbtTag::INT_ARRAY => new LittleEndianNbtTag($type, $this->readIntArray($payload)),
            LittleEndianNbtTag::LONG_ARRAY => new LittleEndianNbtTag($type, $this->readLongArray($payload)),
            default => throw new CorruptWorldDataException("Unsupported NBT tag type $type."),
        };
    }

    private function readList(string $payload, int $depth): LittleEndianNbtTag
    {
        $elementType = $this->readUnsignedByte($payload);
        if ($elementType > LittleEndianNbtTag::LONG_ARRAY) {
            throw new CorruptWorldDataException("Unsupported NBT list element type $elementType.");
        }
        $length = $this->readCollectionLength($payload);
        if ($elementType === LittleEndianNbtTag::END && $length !== 0) {
            throw new CorruptWorldDataException('A non-empty NBT list cannot use the end tag type.');
        }
        $this->countEntries($length);
        $items = [];
        for ($index = 0; $index < $length; ++$index) {
            $items[] = $this->readPayload($payload, $elementType, $depth + 1);
        }

        return LittleEndianNbtTag::list($elementType, $items);
    }

    private function readByteArray(string $payload): string
    {
        $length = $this->readCollectionLength($payload);
        $this->countEntries($length);

        return $this->readBytes($payload, $length);
    }

    /** @return list<int> */
    private function readIntArray(string $payload): array
    {
        $length = $this->readCollectionLength($payload);
        $this->countEntries($length);
        $values = [];
        for ($index = 0; $index < $length; ++$index) {
            $values[] = $this->readSignedInt($payload);
        }

        return $values;
    }

    /** @return list<int> */
    private function readLongArray(string $payload): array
    {
        $length = $this->readCollectionLength($payload);
        $this->countEntries($length);
        $values = [];
        for ($index = 0; $index < $length; ++$index) {
            $values[] = $this->readSignedLong($payload);
        }

        return $values;
    }

    /** @param array<string, LittleEndianNbtTag> $tags */
    private function writeCompound(array $tags, int $depth): string
    {
        $this->assertEncodeDepth($depth);
        $result = '';
        foreach ($tags as $name => $tag) {
            $this->countEncodeEntries(1);
            $result .= chr($tag->type) . $this->writeString($name) . $this->writePayload($tag, $depth + 1);
        }

        return $result . chr(LittleEndianNbtTag::END);
    }

    private function writePayload(LittleEndianNbtTag $tag, int $depth): string
    {
        $this->assertEncodeDepth($depth);
        $value = $tag->value;

        return match ($tag->type) {
            LittleEndianNbtTag::BYTE => pack('c', $this->expectInteger($value, -128, 127)),
            LittleEndianNbtTag::SHORT => pack('v', $this->expectInteger($value, -32_768, 32_767) & 0xffff),
            LittleEndianNbtTag::INT => pack('V', $this->expectInteger($value, -2_147_483_648, 2_147_483_647) & 0xffffffff),
            LittleEndianNbtTag::LONG => $this->writeLong($this->expectInteger($value, PHP_INT_MIN, PHP_INT_MAX)),
            LittleEndianNbtTag::FLOAT => pack('g', $this->expectNumber($value)),
            LittleEndianNbtTag::DOUBLE => pack('e', $this->expectNumber($value)),
            LittleEndianNbtTag::BYTE_ARRAY => $this->writeByteArray($value),
            LittleEndianNbtTag::STRING => $this->writeString($this->expectString($value)),
            LittleEndianNbtTag::LIST => $this->writeList($tag, $depth),
            LittleEndianNbtTag::COMPOUND => $this->writeCompound($this->expectCompound($value), $depth),
            LittleEndianNbtTag::INT_ARRAY => $this->writeIntegerArray($value, false),
            LittleEndianNbtTag::LONG_ARRAY => $this->writeIntegerArray($value, true),
            default => throw new InvalidArgumentException('Unsupported NBT tag type.'),
        };
    }

    private function writeList(LittleEndianNbtTag $tag, int $depth): string
    {
        $items = $this->expectTagList($tag->value);
        $elementType = $tag->listType ?? throw new InvalidArgumentException('NBT list element type is missing.');
        if ($elementType === LittleEndianNbtTag::END && $items !== []) {
            throw new InvalidArgumentException('A non-empty NBT list cannot use the end tag type.');
        }
        $this->countEncodeEntries(count($items));
        $result = chr($elementType) . $this->writeLength(count($items));
        foreach ($items as $item) {
            if ($item->type !== $elementType) {
                throw new InvalidArgumentException('NBT list contains a mismatched element type.');
            }
            $result .= $this->writePayload($item, $depth + 1);
        }

        return $result;
    }

    /** @param int|float|string|array<array-key, mixed> $value */
    private function writeByteArray(int|float|string|array $value): string
    {
        $bytes = $this->expectString($value);
        $this->countEncodeEntries(strlen($bytes));

        return $this->writeLength(strlen($bytes)) . $bytes;
    }

    /** @param int|float|string|array<array-key, mixed> $value */
    private function writeIntegerArray(int|float|string|array $value, bool $long): string
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('NBT integer array value must be a list.');
        }
        $this->countEncodeEntries(count($value));
        $result = $this->writeLength(count($value));
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new InvalidArgumentException('NBT integer array contains a non-integer value.');
            }
            if (!$long && ($item < -2_147_483_648 || $item > 2_147_483_647)) {
                throw new InvalidArgumentException('NBT integer array contains an out-of-range value.');
            }
            $result .= $long ? $this->writeLong($item) : pack('V', $item & 0xffffffff);
        }

        return $result;
    }

    private function readCollectionLength(string $payload): int
    {
        $length = $this->readSignedInt($payload);
        if ($length < 0 || $length > self::MAX_COLLECTION_ENTRIES) {
            throw new CorruptWorldDataException('NBT collection length is outside the configured limit.');
        }

        return $length;
    }

    private function readString(string $payload): string
    {
        $bytes = $this->readBytes($payload, $this->readUnsignedShort($payload));
        if (preg_match('//u', $bytes) !== 1) {
            throw new CorruptWorldDataException('NBT string is not valid UTF-8.');
        }

        return $bytes;
    }

    private function writeString(string $value): string
    {
        $length = strlen($value);
        if ($length > self::MAX_STRING_BYTES || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('NBT string is invalid or exceeds the configured limit.');
        }

        return pack('v', $length) . $value;
    }

    private function readUnsignedByte(string $payload): int
    {
        return ord($this->readBytes($payload, 1));
    }

    private function readSignedByte(string $payload): int
    {
        $value = $this->readUnsignedByte($payload);

        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    private function readUnsignedShort(string $payload): int
    {
        return $this->unpackInteger('vvalue', $this->readBytes($payload, 2));
    }

    private function readSignedShort(string $payload): int
    {
        $value = $this->readUnsignedShort($payload);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function readSignedInt(string $payload): int
    {
        $value = $this->unpackInteger('Vvalue', $this->readBytes($payload, 4));

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private function readSignedLong(string $payload): int
    {
        /** @var array{low: int, high: int} $parts */
        $parts = unpack('Vlow/Vhigh', $this->readBytes($payload, 8));

        return $parts['low'] | ($parts['high'] << 32);
    }

    private function readFloat(string $payload): float
    {
        return $this->unpackFloat('gvalue', $this->readBytes($payload, 4));
    }

    private function readDouble(string $payload): float
    {
        return $this->unpackFloat('evalue', $this->readBytes($payload, 8));
    }

    private function readBytes(string $payload, int $length): string
    {
        if ($length < 0 || $this->offset > strlen($payload) - $length) {
            throw new CorruptWorldDataException('Truncated level.dat NBT payload.');
        }
        $bytes = substr($payload, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    private function writeLength(int $length): string
    {
        if ($length < 0 || $length > self::MAX_COLLECTION_ENTRIES) {
            throw new InvalidArgumentException('NBT collection exceeds the configured limit.');
        }

        return pack('V', $length);
    }

    private function writeLong(int $value): string
    {
        return pack('V2', $value & 0xffffffff, ($value >> 32) & 0xffffffff);
    }

    private function countEntries(int $count): void
    {
        $this->entries += $count;
        if ($this->entries > self::MAX_COLLECTION_ENTRIES) {
            throw new CorruptWorldDataException('NBT payload contains too many values.');
        }
    }

    private function countEncodeEntries(int $count): void
    {
        $this->entries += $count;
        if ($this->entries > self::MAX_COLLECTION_ENTRIES) {
            throw new InvalidArgumentException('NBT payload contains too many values.');
        }
    }

    private function assertDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CorruptWorldDataException('NBT payload exceeds the configured nesting limit.');
        }
    }

    private function assertEncodeDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('NBT payload exceeds the configured nesting limit.');
        }
    }

    /** @param int|float|string|array<array-key, mixed> $value */
    private function expectInteger(int|float|string|array $value, int $minimum, int $maximum): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('NBT numeric value is outside its tag range.');
        }

        return $value;
    }

    /** @param int|float|string|array<array-key, mixed> $value */
    private function expectNumber(int|float|string|array $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('NBT floating-point tag requires a numeric value.');
        }

        return (float) $value;
    }

    /** @param int|float|string|array<array-key, mixed> $value */
    private function expectString(int|float|string|array $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('NBT string tag requires a string value.');
        }

        return $value;
    }

    /**
     * @param int|float|string|array<array-key, mixed> $value
     * @return array<string, LittleEndianNbtTag>
     */
    private function expectCompound(int|float|string|array $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('NBT compound value must be a string-keyed map.');
        }
        foreach ($value as $name => $tag) {
            if (!is_string($name) || !$tag instanceof LittleEndianNbtTag) {
                throw new InvalidArgumentException('NBT compound contains an invalid entry.');
            }
        }

        return $value;
    }

    /**
     * @param int|float|string|array<array-key, mixed> $value
     * @return list<LittleEndianNbtTag>
     */
    private function expectTagList(int|float|string|array $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('NBT list value must be a list.');
        }
        foreach ($value as $tag) {
            if (!$tag instanceof LittleEndianNbtTag) {
                throw new InvalidArgumentException('NBT list contains an invalid entry.');
            }
        }

        return $value;
    }

    private function unpackInteger(string $format, string $bytes): int
    {
        $result = unpack($format, $bytes);
        if ($result === false || !isset($result['value']) || !is_int($result['value'])) {
            throw new CorruptWorldDataException('Unable to decode level.dat integer value.');
        }

        return $result['value'];
    }

    private function unpackFloat(string $format, string $bytes): float
    {
        $result = unpack($format, $bytes);
        if ($result === false || !isset($result['value']) || !is_float($result['value'])) {
            throw new CorruptWorldDataException('Unable to decode level.dat floating-point value.');
        }

        return $result['value'];
    }
}
