<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use InvalidArgumentException;

/** Immutable, bounded little-endian NBT compound attached to one item stack. */
final readonly class ItemNbt
{
    public const int MAX_BYTES = 100_000;

    private function __construct(private string $binary) {}

    public static function empty(): self
    {
        return new self("\x0a\x00\x00\x00");
    }

    public static function fromBinary(string $binary): self
    {
        if (strlen($binary) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Item NBT exceeds its byte limit.');
        }
        try {
            (new LittleEndianNbtCodec())->decodeRootCompound($binary);
        } catch (CorruptWorldDataException|InvalidArgumentException $error) {
            throw new InvalidArgumentException('Item NBT must be one valid compound root.', previous: $error);
        }

        return new self($binary);
    }

    public function toBinary(): string
    {
        return $this->binary;
    }

    public function isEmpty(): bool
    {
        return $this->binary === self::empty()->binary;
    }

    public function equals(self $other): bool
    {
        return $this->binary === $other->binary;
    }

    public function withTag(string $name, Tag $tag): self
    {
        if ($name === '' || strlen($name) > 128) {
            throw new InvalidArgumentException('Item NBT tag name exceeds its limit.');
        }
        $root = (new LittleEndianNbtCodec())->decodeRootCompound($this->binary);
        $root[$name] = $tag->toInternal();

        return self::fromBinary((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function tag(string $name): ?Tag
    {
        $tag = (new LittleEndianNbtCodec())->decodeRootCompound($this->binary)[$name] ?? null;

        return $tag === null ? null : Tag::fromInternal($tag);
    }

    public function withoutTag(string $name): self
    {
        $root = (new LittleEndianNbtCodec())->decodeRootCompound($this->binary);
        unset($root[$name]);

        return self::fromBinary((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function withString(string $name, string $value): self
    {
        if (strlen($value) > 1_024) {
            throw new InvalidArgumentException('Item NBT string exceeds its limit.');
        }

        return $this->withTag($name, Tag::string($value));
    }

    public function string(string $name): ?string
    {
        $tag = $this->tag($name);

        return $tag?->type() === TagType::STRING && is_string($tag->value()) ? $tag->value() : null;
    }

    public function int(string $name): ?int
    {
        $tag = $this->tag($name);

        return $tag?->type() === TagType::INT && is_int($tag->value()) ? $tag->value() : null;
    }
}
