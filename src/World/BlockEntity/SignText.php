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

namespace Bedriox\Server\World\BlockEntity;

use InvalidArgumentException;

/** Immutable, strictly bounded text displayed on one side of a sign. */
final readonly class SignText
{
    public const int LINE_COUNT = 4;
    public const int MAXIMUM_LINE_BYTES = 255;
    public const int MAXIMUM_TEXT_BYTES = 1_020;

    /** @var array{string, string, string, string} */
    private array $lines;

    /** @param array<array-key, mixed> $lines */
    public function __construct(array $lines = [], public int $color = 0xff000000, public bool $glowing = false)
    {
        if (count($lines) > self::LINE_COUNT) {
            throw new InvalidArgumentException('Sign text cannot contain more than four lines.');
        }
        $validated = ['', '', '', ''];
        $totalBytes = 0;
        foreach ($lines as $index => $line) {
            if (!is_int($index) || $index < 0 || $index >= self::LINE_COUNT || !is_string($line)
                || preg_match('//u', $line) !== 1 || str_contains($line, "\n") || str_contains($line, "\r")
                || strlen($line) > self::MAXIMUM_LINE_BYTES) {
                throw new InvalidArgumentException('Sign text contains an invalid or oversized line.');
            }
            $totalBytes += strlen($line);
            $validated[$index] = $line;
        }
        if ($totalBytes > self::MAXIMUM_TEXT_BYTES) {
            throw new InvalidArgumentException('Sign text exceeds its total byte limit.');
        }
        if ($color < 0 || $color > 0xffffffff) {
            throw new InvalidArgumentException('Sign text color must be an unsigned ARGB value.');
        }
        $this->lines = $validated;
    }

    public static function fromBlob(string $blob, int $color = 0xff000000, bool $glowing = false): self
    {
        if (preg_match('//u', $blob) !== 1 || strlen($blob) > self::MAXIMUM_TEXT_BYTES + self::LINE_COUNT - 1) {
            throw new InvalidArgumentException('Sign text blob is invalid or exceeds its size limit.');
        }
        $lines = explode("\n", $blob);
        if (count($lines) > self::LINE_COUNT) {
            throw new InvalidArgumentException('Sign text blob cannot contain more than four lines.');
        }

        return new self($lines, $color, $glowing);
    }

    /** @return array{string, string, string, string} */
    public function lines(): array
    {
        return $this->lines;
    }

    public function blob(): string
    {
        return rtrim(implode("\n", $this->lines), "\n");
    }
}
