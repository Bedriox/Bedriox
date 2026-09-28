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

namespace Bedriox\Server\World\Block;

use Bedriox\Data\CanonicalBlockState;
use InvalidArgumentException;

/**
 * Process-local canonical-state registry.
 *
 * IDs are deliberately derived independently of Bedrock palette order. They are not a persistence or plugin API.
 */
final readonly class BlockStateRegistry
{
    /** @var list<CanonicalBlockState> */
    private array $states;

    /** @var array<string, int> */
    private array $internalIdsByKey;

    /** @param list<mixed> $states */
    public function __construct(array $states)
    {
        if ($states === [] || count($states) > 100_000) {
            throw new InvalidArgumentException('Internal block-state registry must be non-empty and bounded.');
        }
        $statesByKey = [];
        foreach ($states as $state) {
            if (!$state instanceof CanonicalBlockState) {
                throw new InvalidArgumentException('Internal block-state registry contains an invalid state.');
            }
            $key = $state->canonicalKey();
            if (isset($statesByKey[$key])) {
                throw new InvalidArgumentException('Internal block-state registry contains a duplicate state.');
            }
            $statesByKey[$key] = $state;
        }
        ksort($statesByKey, SORT_STRING);
        $this->states = array_values($statesByKey);
        $this->internalIdsByKey = array_flip(array_keys($statesByKey));
    }

    public function internalId(CanonicalBlockState $state): InternalBlockStateId
    {
        $value = $this->internalIdsByKey[$state->canonicalKey()] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('Canonical block state is not registered internally.');
        }
        return new InternalBlockStateId($value);
    }

    public function state(InternalBlockStateId $id): CanonicalBlockState
    {
        return $this->states[$id->value]
            ?? throw new InvalidArgumentException('Internal block-state ID is outside the current registry.');
    }

    /** @return list<CanonicalBlockState> */
    public function states(): array
    {
        return $this->states;
    }
}
