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

namespace Bedriox\Api\World\Generator;

use InvalidArgumentException;

final readonly class GeneratorContext
{
    /** @var array<string, mixed> */
    public array $options;

    /** @param array<mixed, mixed> $options */
    public function __construct(
        public int $seed,
        public string $dimension,
        array $options = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.-]{0,63}$/D', $dimension) !== 1) {
            throw new InvalidArgumentException('Generator dimension must be a bounded namespaced identifier.');
        }
        $entries = 0;
        $this->options = self::normalizeMap($options, 1, $entries);
        $encoded = json_encode($this->options, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > 32_768) {
            throw new InvalidArgumentException('Generator options exceed their encoded byte limit.');
        }
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private static function normalizeMap(array $values, int $depth, int &$entries): array
    {
        if (($values !== [] && array_is_list($values)) || $depth > 8) {
            throw new InvalidArgumentException('Generator options must be a bounded string-keyed map.');
        }
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1
                || ++$entries > 256) {
                throw new InvalidArgumentException('Generator options contain an invalid key or too many values.');
            }
            $result[$key] = self::normalizeValue($value, $depth + 1, $entries);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private static function normalizeValue(mixed $value, int $depth, int &$entries): mixed
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
            if (strlen($value) > 4_096 || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Generator option strings must be bounded UTF-8.');
            }
            return $value;
        }
        if (!is_array($value) || $depth > 8) {
            throw new InvalidArgumentException('Generator options contain an unsupported or deeply nested value.');
        }
        $result = [];
        if (array_is_list($value)) {
            foreach ($value as $entry) {
                if (++$entries > 256) {
                    throw new InvalidArgumentException('Generator options contain too many values.');
                }
                $result[] = self::normalizeValue($entry, $depth + 1, $entries);
            }
            return $result;
        }

        return self::normalizeMap($value, $depth, $entries);
    }
}
