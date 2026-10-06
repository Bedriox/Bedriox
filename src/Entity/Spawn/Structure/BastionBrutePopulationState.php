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

/** Durable bounded record of structure-owned inhabitants already admitted to a world. */
final class BastionBrutePopulationState
{
    public const int MAXIMUM_CLAIMS = 16_384;

    /** @var array<string, true> */
    private array $claims = [];

    /** @param array<int, mixed> $claims */
    public function __construct(array $claims = [])
    {
        if (!array_is_list($claims) || count($claims) > self::MAXIMUM_CLAIMS) {
            throw new InvalidArgumentException('Bastion population claims are malformed or oversized.');
        }
        foreach ($claims as $claim) {
            if (!is_string($claim)) {
                throw new InvalidArgumentException('Bastion population claim is invalid.');
            }
            self::validateClaim($claim);
            $this->claims[$claim] = true;
        }
        if (count($this->claims) !== count($claims)) {
            throw new InvalidArgumentException('Bastion population claims contain duplicates.');
        }
    }

    public function contains(string $claim): bool
    {
        self::validateClaim($claim);

        return isset($this->claims[$claim]);
    }

    public function claim(string $claim): bool
    {
        self::validateClaim($claim);
        if (isset($this->claims[$claim])) {
            return false;
        }
        if (count($this->claims) >= self::MAXIMUM_CLAIMS) {
            throw new InvalidArgumentException('Bastion population claim capacity is exhausted.');
        }
        $this->claims[$claim] = true;

        return true;
    }

    public function encode(): string
    {
        $claims = array_keys($this->claims);
        sort($claims, SORT_STRING);

        return json_encode(['schema' => 1, 'claims' => $claims], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $encoded): self
    {
        if (strlen($encoded) > 1_048_576) {
            throw new InvalidArgumentException('Bastion population state is oversized.');
        }
        try {
            $data = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Bastion population state is malformed.', previous: $error);
        }
        if (!is_array($data) || array_keys($data) !== ['schema', 'claims'] || $data['schema'] !== 1
            || !is_array($data['claims']) || !array_is_list($data['claims'])) {
            throw new InvalidArgumentException('Bastion population state has an unsupported schema.');
        }

        $claims = [];
        foreach ($data['claims'] as $claim) {
            if (!is_string($claim)) {
                throw new InvalidArgumentException('Bastion population state contains a malformed claim.');
            }
            $claims[] = $claim;
        }

        return new self($claims);
    }

    private static function validateClaim(string $claim): void
    {
        if (strlen($claim) > 96
            || preg_match('/^bastion:-?(?:0|[1-9][0-9]*):-?(?:0|[1-9][0-9]*):[0-3]$/D', $claim) !== 1) {
            throw new InvalidArgumentException('Bastion population claim is invalid.');
        }
    }
}
