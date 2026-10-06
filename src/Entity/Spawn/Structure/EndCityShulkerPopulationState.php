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

namespace Bedriox\Server\Entity\Spawn\Structure;

use InvalidArgumentException;
use JsonException;

final class EndCityShulkerPopulationState
{
    private const int MAXIMUM_CLAIMS = 16_384;

    /** @var array<string, true> */
    private array $claims = [];

    /** @param list<string> $claims */
    public function __construct(array $claims = [])
    {
        if (count($claims) > self::MAXIMUM_CLAIMS) {
            throw new InvalidArgumentException('End City Shulker claims are oversized.');
        }
        foreach ($claims as $claim) {
            self::validate($claim);
            $this->claims[$claim] = true;
        }
        if (count($claims) !== count($this->claims)) {
            throw new InvalidArgumentException('End City Shulker claims contain duplicates.');
        }
    }

    public function contains(string $claim): bool
    {
        self::validate($claim);

        return isset($this->claims[$claim]);
    }

    public function claim(string $claim): void
    {
        self::validate($claim);
        if (!isset($this->claims[$claim]) && count($this->claims) >= self::MAXIMUM_CLAIMS) {
            throw new InvalidArgumentException('End City Shulker claim capacity is exhausted.');
        }
        $this->claims[$claim] = true;
    }

    public function encode(): string
    {
        $claims = array_keys($this->claims);
        sort($claims, SORT_STRING);

        return json_encode(['schema' => 1, 'claims' => $claims], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $payload): self
    {
        if (strlen($payload) > 1_048_576) {
            throw new InvalidArgumentException('End City Shulker state is oversized.');
        }
        try {
            $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('End City Shulker state is malformed.', previous: $error);
        }
        if (!is_array($data) || array_keys($data) !== ['schema', 'claims'] || $data['schema'] !== 1
            || !is_array($data['claims']) || !array_is_list($data['claims'])) {
            throw new InvalidArgumentException('End City Shulker state has an unsupported schema.');
        }

        $claims = [];
        foreach ($data['claims'] as $claim) {
            if (!is_string($claim)) {
                throw new InvalidArgumentException('End City Shulker state contains a malformed claim.');
            }
            $claims[] = $claim;
        }

        return new self($claims);
    }

    private static function validate(string $claim): void
    {
        if (strlen($claim) > 128 || preg_match('/^end_city:(?:-?(?:0|[1-9][0-9]*):){4}[0-3]$/D', $claim) !== 1) {
            throw new InvalidArgumentException('End City Shulker claim is invalid.');
        }
    }
}
