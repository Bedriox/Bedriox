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

namespace Bedriox\Api\World\Particle;

use InvalidArgumentException;
use JsonException;

/** Bounded MoLang variable values supplied to a named Bedrock particle effect. */
final readonly class ParticleVariables
{
    private const int MAXIMUM_VARIABLES = 32;
    private const int MAXIMUM_JSON_BYTES = 4_096;

    /** @var array<string, bool|float|int|string> */
    private array $values;

    /** @param array<string, bool|float|int|string> $values */
    public function __construct(array $values)
    {
        if (count($values) > self::MAXIMUM_VARIABLES) {
            throw new InvalidArgumentException('Particle variable capacity was exceeded.');
        }
        foreach ($values as $name => $value) {
            if (preg_match('/^variable\.[a-z0-9_]{1,64}$/D', $name) !== 1) {
                throw new InvalidArgumentException('Particle variable names must use the variable.* namespace.');
            }
            if ((is_float($value) && !is_finite($value)) || (is_string($value) && strlen($value) > 256)) {
                throw new InvalidArgumentException('Particle variable value is invalid or unbounded.');
            }
        }
        ksort($values, SORT_STRING);
        $this->values = $values;
        if (strlen($this->toJson()) > self::MAXIMUM_JSON_BYTES) {
            throw new InvalidArgumentException('Encoded particle variables exceed the supported size.');
        }
    }

    /** @return array<string, bool|float|int|string> */
    public function values(): array
    {
        return $this->values;
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Particle variables cannot be encoded.', previous: $exception);
        }
    }
}
