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

namespace Bedriox\Server\World\Generation\Noise;

use InvalidArgumentException;

/** Unsigned 64-bit arithmetic represented as two portable 32-bit limbs. */
final readonly class UnsignedLong
{
    private const int LIMB_BASE = 65_536;
    private const int WORD_BASE = 4_294_967_296;
    private const int WORD_MASK = 0xffff_ffff;

    private function __construct(
        public int $high,
        public int $low,
    ) {}

    public static function fromInt(int $value): self
    {
        return new self(($value >> 32) & self::WORD_MASK, $value & self::WORD_MASK);
    }

    public static function fromHex(string $hex): self
    {
        if (preg_match('/^(?:0x)?([0-9a-fA-F]{1,16})$/D', $hex, $matches) !== 1) {
            throw new InvalidArgumentException('Unsigned 64-bit hexadecimal value is invalid.');
        }
        $digits = str_pad(strtolower($matches[1]), 16, '0', STR_PAD_LEFT);

        return new self((int) hexdec(substr($digits, 0, 8)), (int) hexdec(substr($digits, 8, 8)));
    }

    public static function fromBigEndian(string $bytes): self
    {
        if (strlen($bytes) !== 8) {
            throw new InvalidArgumentException('Unsigned 64-bit big-endian input must contain eight bytes.');
        }
        /** @var array{high: int, low: int} $words */
        $words = unpack('Nhigh/Nlow', $bytes);

        return new self($words['high'], $words['low']);
    }

    public function toHex(): string
    {
        return sprintf('%08x%08x', $this->high, $this->low);
    }

    public function xor(self $other): self
    {
        return new self(($this->high ^ $other->high) & self::WORD_MASK, ($this->low ^ $other->low) & self::WORD_MASK);
    }

    public function add(self $other): self
    {
        $low = $this->low + $other->low;
        $carry = $low >= self::WORD_BASE ? 1 : 0;

        return new self(($this->high + $other->high + $carry) & self::WORD_MASK, $low & self::WORD_MASK);
    }

    public function rotateLeft(int $bits): self
    {
        $bits &= 63;
        if ($bits === 0) {
            return $this;
        }
        if ($bits === 32) {
            return new self($this->low, $this->high);
        }
        if ($bits > 32) {
            return (new self($this->low, $this->high))->rotateLeft($bits - 32);
        }

        return new self(
            (($this->high << $bits) | ($this->low >> (32 - $bits))) & self::WORD_MASK,
            (($this->low << $bits) | ($this->high >> (32 - $bits))) & self::WORD_MASK,
        );
    }

    public function shiftLeft(int $bits): self
    {
        if ($bits < 0 || $bits > 63) {
            throw new InvalidArgumentException('Unsigned 64-bit shift is outside 0 through 63.');
        }
        if ($bits === 0) {
            return $this;
        }
        if ($bits >= 32) {
            return new self(($this->low << ($bits - 32)) & self::WORD_MASK, 0);
        }

        return new self(
            (($this->high << $bits) | ($this->low >> (32 - $bits))) & self::WORD_MASK,
            ($this->low << $bits) & self::WORD_MASK,
        );
    }

    public function shiftRight(int $bits): self
    {
        if ($bits < 0 || $bits > 63) {
            throw new InvalidArgumentException('Unsigned 64-bit shift is outside 0 through 63.');
        }
        if ($bits === 0) {
            return $this;
        }
        if ($bits >= 32) {
            return new self(0, $this->high >> ($bits - 32));
        }

        return new self(
            $this->high >> $bits,
            (($this->low >> $bits) | ($this->high << (32 - $bits))) & self::WORD_MASK,
        );
    }

    public function shiftRightSigned(int $bits): self
    {
        $shifted = $this->shiftRight($bits);
        if ($bits === 0 || ($this->high & 0x8000_0000) === 0) {
            return $shifted;
        }
        if ($bits < 32) {
            return new self($shifted->high | ((self::WORD_MASK << (32 - $bits)) & self::WORD_MASK), $shifted->low);
        }

        return new self(self::WORD_MASK, $shifted->low | ((self::WORD_MASK << (64 - $bits)) & self::WORD_MASK));
    }

    public function multiply(self $other): self
    {
        $left = [
            $this->low & 0xffff,
            $this->low >> 16,
            $this->high & 0xffff,
            $this->high >> 16,
        ];
        $right = [
            $other->low & 0xffff,
            $other->low >> 16,
            $other->high & 0xffff,
            $other->high >> 16,
        ];
        $result = [0, 0, 0, 0];
        $carry = 0;
        for ($word = 0; $word < 4; ++$word) {
            $value = $carry;
            for ($leftIndex = 0; $leftIndex <= $word; ++$leftIndex) {
                $value += $left[$leftIndex] * $right[$word - $leftIndex];
            }
            $result[$word] = $value & 0xffff;
            $carry = intdiv($value, self::LIMB_BASE);
        }

        return new self(($result[3] << 16) | $result[2], ($result[1] << 16) | $result[0]);
    }

    public function high53Fraction(): float
    {
        $shifted = $this->shiftRight(11);

        return (($shifted->high * self::WORD_BASE) + $shifted->low) / 9_007_199_254_740_992.0;
    }
}
