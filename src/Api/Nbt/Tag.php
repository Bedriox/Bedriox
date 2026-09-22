<?php

declare(strict_types=1);

namespace Bedriox\Api\Nbt;

use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use InvalidArgumentException;

/** One immutable NBT value. Lists are homogeneous; compounds have named children. */
final readonly class Tag
{
    private function __construct(private LittleEndianNbtTag $value) {}

    public static function byte(int $value): self
    {
        return new self(LittleEndianNbtTag::byte($value));
    }
    public static function short(int $value): self
    {
        return new self(new LittleEndianNbtTag(LittleEndianNbtTag::SHORT, $value));
    }
    public static function int(int $value): self
    {
        return new self(LittleEndianNbtTag::int($value));
    }
    public static function long(int $value): self
    {
        return new self(LittleEndianNbtTag::long($value));
    }
    public static function float(float $value): self
    {
        return new self(LittleEndianNbtTag::float($value));
    }
    public static function double(float $value): self
    {
        return new self(new LittleEndianNbtTag(LittleEndianNbtTag::DOUBLE, $value));
    }
    public static function string(string $value): self
    {
        return new self(LittleEndianNbtTag::string($value));
    }
    public static function byteArray(string $value): self
    {
        return new self(new LittleEndianNbtTag(LittleEndianNbtTag::BYTE_ARRAY, $value));
    }

    /** @param list<int> $values */
    public static function intArray(array $values): self
    {
        return new self(new LittleEndianNbtTag(LittleEndianNbtTag::INT_ARRAY, $values));
    }
    /** @param list<int> $values */
    public static function longArray(array $values): self
    {
        return new self(new LittleEndianNbtTag(LittleEndianNbtTag::LONG_ARRAY, $values));
    }

    /** @param list<Tag> $values */
    public static function list(TagType $type, array $values): self
    {
        foreach ($values as $value) {
            if ($value->type() !== $type) {
                throw new InvalidArgumentException('NBT list values must match the declared type.');
            }
        }

        return new self(LittleEndianNbtTag::list($type->value, array_map(static fn(self $value): LittleEndianNbtTag => $value->value, $values)));
    }

    /** @param array<string, Tag> $values */
    public static function compound(array $values): self
    {
        $tags = [];
        foreach ($values as $name => $value) {
            if ($name === '') {
                throw new InvalidArgumentException('NBT compound entries must have non-empty names and tag values.');
            }
            $tags[$name] = $value->value;
        }

        return new self(LittleEndianNbtTag::compound($tags));
    }

    public function type(): TagType
    {
        return TagType::from($this->value->type);
    }

    /** @return int|float|string|array<int|string, self|int> */
    public function value(): int|float|string|array
    {
        if ($this->value->type === LittleEndianNbtTag::LIST || $this->value->type === LittleEndianNbtTag::COMPOUND) {
            if (!is_array($this->value->value)) {
                throw new \LogicException('NBT collection has no children.');
            }
            $children = [];
            foreach ($this->value->value as $name => $child) {
                if (!$child instanceof LittleEndianNbtTag) {
                    throw new \LogicException('NBT collection contains an invalid child.');
                }
                $children[$name] = self::fromInternal($child);
            }

            return $children;
        }

        if (is_array($this->value->value)) {
            $numbers = [];
            foreach ($this->value->value as $number) {
                if (!is_int($number)) {
                    throw new \LogicException('NBT numeric array contains an invalid value.');
                }
                $numbers[] = $number;
            }

            return $numbers;
        }

        return $this->value->value;
    }

    /** @internal */
    public static function fromInternal(LittleEndianNbtTag $value): self
    {
        return new self($value);
    }

    /** @internal */
    public function toInternal(): LittleEndianNbtTag
    {
        return $this->value;
    }
}
