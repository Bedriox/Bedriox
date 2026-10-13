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

namespace Bedriox\Server\World\Generation\Geology;

use Bedriox\Server\World\Generation\Noise\SimpleRandom;
use InvalidArgumentException;
use Random\Engine\Mt19937;

/** Deterministic 32-bit MT19937 stream used by overworld decoration features. */
final class MersenneTwister
{
    private const int UINT32_MASK = 0xffff_ffff;

    private readonly Mt19937 $engine;

    public function __construct(int $seed)
    {
        $this->engine = new Mt19937($seed & self::UINT32_MASK, MT_RAND_MT19937);
    }

    public function nextInt(int $bound): int
    {
        if ($bound < 1 || $bound > self::UINT32_MASK) {
            throw new InvalidArgumentException('Mersenne Twister integer bound must be between 1 and 4294967295.');
        }

        return $this->nextUint32() % $bound;
    }

    public function nextFloat(): float
    {
        return SimpleRandom::float32($this->nextUint32() / 4_294_967_296.0);
    }

    public function nextUint32(): int
    {
        $decoded = unpack('Vvalue', $this->engine->generate());
        if ($decoded === false || !isset($decoded['value']) || !is_int($decoded['value'])) {
            throw new \LogicException('Native MT19937 returned an invalid word.');
        }

        return $decoded['value'];
    }
}
