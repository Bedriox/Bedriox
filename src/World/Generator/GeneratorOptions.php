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

namespace Bedriox\Server\World\Generator;

use InvalidArgumentException;
use JsonException;

/** Deterministic, JSON-safe options suitable for persistence and worker transfer. */
final readonly class GeneratorOptions
{
    public const int MAXIMUM_ENCODED_BYTES = 32_768;
    public const int MAXIMUM_DEPTH = 8;
    public const int MAXIMUM_ENTRIES = 256;
    public const int MAXIMUM_KEY_BYTES = 64;
    public const int MAXIMUM_STRING_BYTES = 4_096;

    /** @var array<string, mixed> */
    private array $values;

    private string $canonicalJson;

    /** @param array<mixed, mixed> $values */
    public function __construct(array $values = [])
    {
        $entries = 0;
        $this->values = self::normalizeMap($values, 1, $entries);
        $encoded = json_encode(
            $this->values,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (strlen($encoded) > self::MAXIMUM_ENCODED_BYTES) {
            throw new InvalidArgumentException('Generator options exceed their encoded byte limit.');
        }
        $this->canonicalJson = $encoded;
    }

    /** Reconstructs bounded options received through persistence or a worker boundary. */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAXIMUM_ENCODED_BYTES) {
            throw new InvalidArgumentException('Generator options exceed their encoded byte limit.');
        }
        try {
            $decoded = json_decode($json, true, self::MAXIMUM_DEPTH + 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Generator options are not valid JSON.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Generator options root must be a string-keyed map.');
        }

        return new self($decoded);
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonicalJson);
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private static function normalizeMap(array $values, int $depth, int &$entries): array
    {
        if (array_is_list($values) && $values !== []) {
            throw new InvalidArgumentException('Generator options root must be a string-keyed map.');
        }
        self::guardDepth($depth);
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $key) !== 1) {
                throw new InvalidArgumentException('Generator option keys must be bounded identifiers.');
            }
            self::countEntry($entries);
            $normalized[$key] = self::normalizeValue($value, $depth + 1, $entries);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return bool|float|int|string|array<mixed>|null */
    private static function normalizeValue(mixed $value, int $depth, int &$entries): bool|float|int|string|array|null
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Generator option numbers must be finite.');
            }

            return $value;
        }
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8') || strlen($value) > self::MAXIMUM_STRING_BYTES) {
                throw new InvalidArgumentException('Generator option strings must be valid UTF-8 and bounded.');
            }

            return $value;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Generator options contain an unsupported value.');
        }
        self::guardDepth($depth);
        if (array_is_list($value)) {
            $normalized = [];
            foreach ($value as $entry) {
                self::countEntry($entries);
                $normalized[] = self::normalizeValue($entry, $depth + 1, $entries);
            }

            return $normalized;
        }

        return self::normalizeMap($value, $depth, $entries);
    }

    private static function guardDepth(int $depth): void
    {
        if ($depth > self::MAXIMUM_DEPTH) {
            throw new InvalidArgumentException('Generator options exceed their nesting-depth limit.');
        }
    }

    private static function countEntry(int &$entries): void
    {
        if (++$entries > self::MAXIMUM_ENTRIES) {
            throw new InvalidArgumentException('Generator options exceed their entry limit.');
        }
    }
}
