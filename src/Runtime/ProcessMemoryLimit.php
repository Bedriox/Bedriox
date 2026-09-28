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

namespace Bedriox\Server\Runtime;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProcessMemoryLimit
{
    private const int MINIMUM_BYTES = 128_000_000;
    private const int MAXIMUM_BYTES = 68_719_476_736;

    /**
     * @param null|Closure(string, string): (string|false) $setter
     * @param null|Closure(string): (string|false)         $getter
     */
    public function __construct(
        private ?Closure $setter = null,
        private ?Closure $getter = null,
    ) {}

    public static function parse(string $value): int
    {
        if ($value === '0') {
            return 0;
        }
        if (preg_match('/\A([1-9][0-9]*)(MB|GB|MiB|GiB)\z/Di', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Memory limit must be 0 or a canonical integer followed by MB, GB, MiB, or GiB.');
        }
        $quantity = filter_var($matches[1], FILTER_VALIDATE_INT);
        if (!is_int($quantity)) {
            throw new InvalidArgumentException('Memory limit is outside its supported range.');
        }
        $multiplier = match (strtolower($matches[2])) {
            'mb' => 1_000_000,
            'gb' => 1_000_000_000,
            'mib' => 1_048_576,
            'gib' => 1_073_741_824,
            default => throw new InvalidArgumentException('Memory limit unit is unsupported.'),
        };
        if ($quantity > intdiv(self::MAXIMUM_BYTES, $multiplier)) {
            throw new InvalidArgumentException('Memory limit is outside its supported range.');
        }
        $bytes = $quantity * $multiplier;
        if ($bytes < self::MINIMUM_BYTES) {
            throw new InvalidArgumentException('Memory limit must be 0 or at least 128MB.');
        }

        return $bytes;
    }

    public function apply(int $bytes): void
    {
        if ($bytes !== 0 && ($bytes < self::MINIMUM_BYTES || $bytes > self::MAXIMUM_BYTES)) {
            throw new InvalidArgumentException('Memory limit byte value is outside its supported range.');
        }
        $configured = $bytes === 0 ? '-1' : (string) $bytes;
        $setter = $this->setter ?? ini_set(...);
        $getter = $this->getter ?? ini_get(...);
        if ($setter('memory_limit', $configured) === false) {
            throw new RuntimeException('Unable to apply the configured main-process memory limit.');
        }
        $effective = $getter('memory_limit');
        if (!is_string($effective) || !self::matchesEffectiveLimit($effective, $bytes)) {
            throw new RuntimeException('The configured main-process memory limit could not be verified.');
        }
    }

    private static function matchesEffectiveLimit(string $effective, int $expectedBytes): bool
    {
        if ($expectedBytes === 0) {
            return $effective === '-1';
        }

        return preg_match('/\A[0-9]+\z/D', $effective) === 1
            && filter_var($effective, FILTER_VALIDATE_INT) === $expectedBytes;
    }
}
