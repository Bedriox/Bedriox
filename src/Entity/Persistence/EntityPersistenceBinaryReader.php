<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use RuntimeException;

/** @internal */
final class EntityPersistenceBinaryReader
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    /** @phpstan-impure */
    public function byte(): int
    {
        return ord($this->read(1));
    }

    /** @phpstan-impure */
    public function unsignedShort(): int
    {
        $value = unpack('nvalue', $this->read(2))['value'] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Entity persistence unsigned short cannot be decoded.');
        }

        return $value;
    }

    /** @phpstan-impure */
    public function unsignedInt(): int
    {
        $value = unpack('Nvalue', $this->read(4))['value'] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Entity persistence unsigned integer cannot be decoded.');
        }

        return $value;
    }

    /** @phpstan-impure */
    public function signedInt(): int
    {
        $value = $this->unsignedInt();

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    /** @phpstan-impure */
    public function nonNegativeLong(): int
    {
        $value = unpack('Jvalue', $this->read(8))['value'] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('Entity persistence revision cannot be decoded.');
        }

        return $value;
    }

    /** @phpstan-impure */
    public function double(): float
    {
        $value = unpack('Evalue', $this->read(8))['value'] ?? null;
        if (!is_float($value) || !is_finite($value)) {
            throw new RuntimeException('Entity persistence floating-point value is invalid.');
        }

        return $value;
    }

    /** @phpstan-impure */
    public function string(int $maximumBytes): string
    {
        $length = $this->unsignedShort();
        if ($length > $maximumBytes) {
            throw new RuntimeException('Entity persistence string is oversized.');
        }
        $value = $this->read($length);
        if (preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Entity persistence string is not valid UTF-8.');
        }

        return $value;
    }

    /** @phpstan-impure */
    public function sizedBytes(int $maximumBytes): string
    {
        $length = $this->unsignedInt();
        if ($length > $maximumBytes) {
            throw new RuntimeException('Entity persistence byte field is oversized.');
        }

        return $this->read($length);
    }

    /** @phpstan-impure */
    public function read(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->bytes)) {
            throw new RuntimeException('Entity persistence data is truncated.');
        }
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function finish(): void
    {
        if ($this->offset !== strlen($this->bytes)) {
            throw new RuntimeException('Entity persistence data contains trailing bytes.');
        }
    }
}
