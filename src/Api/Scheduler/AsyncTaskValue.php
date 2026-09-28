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

namespace Bedriox\Api\Scheduler;

use JsonException;
use ReflectionReference;

/** An immutable, JSON-compatible value admitted across the plugin-worker boundary. */
final readonly class AsyncTaskValue
{
    public const MAX_DEPTH = 16;
    public const MAX_ELEMENTS = 4096;
    public const MAX_STRING_BYTES = 65536;
    public const MAX_ENCODED_BYTES = 1048576;

    private mixed $value;

    public function __construct(mixed $value)
    {
        $elements = 0;
        $this->validate($value, 0, $elements);
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $failure) {
            throw new \InvalidArgumentException('Async task values must contain valid UTF-8 strings.', previous: $failure);
        }
        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new \InvalidArgumentException('Async task value exceeds the encoded byte limit.');
        }
        $this->value = $value;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    private function validate(mixed $value, int $depth, int &$elements): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Async task value exceeds the nesting limit.');
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_BYTES) {
                throw new \InvalidArgumentException('Async task string exceeds the byte limit.');
            }

            return;
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Async task floating-point values must be finite.');
            }

            return;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Async task values may contain only scalars, lists, and string-keyed maps.');
        }
        $isList = array_is_list($value);
        foreach (array_keys($value) as $key) {
            if (ReflectionReference::fromArrayElement($value, $key) !== null) {
                throw new \InvalidArgumentException('Async task values cannot contain references.');
            }
            if (!$isList && (!is_string($key) || $key === '' || strlen($key) > 256)) {
                throw new \InvalidArgumentException('Async task map keys must contain between 1 and 256 bytes.');
            }
            if (++$elements > self::MAX_ELEMENTS) {
                throw new \InvalidArgumentException('Async task value exceeds the element limit.');
            }
            $this->validate($value[$key], $depth + 1, $elements);
        }
    }
}
