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

namespace Bedriox\Api\Event\Block;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

/** Fired before an authoritative brewing result commits. */
final class BrewingEvent extends CancellableEvent
{
    /** @var list<ItemStack|null> */
    private array $results;

    /** @param array<mixed> $results */
    public function __construct(public readonly BlockPosition $position, array $results)
    {
        $this->results = self::validateResults($results);
    }

    /** @return list<ItemStack|null> */
    public function results(): array
    {
        return $this->results;
    }

    /** @param array<mixed> $results */
    public function setResults(array $results): void
    {
        $this->assertMutable();
        $this->results = self::validateResults($results);
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->results];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_array($state[1])) {
            throw new InvalidArgumentException('Invalid brewing event state.');
        }
        parent::replaceState($state[0]);
        $this->results = self::validateResults($state[1]);
    }

    /**
     * @param array<mixed> $results
     * @return list<ItemStack|null>
     */
    private static function validateResults(array $results): array
    {
        if (!array_is_list($results) || count($results) !== 3) {
            throw new InvalidArgumentException('Brewing results must contain exactly three bottle slots.');
        }
        $validated = [];
        foreach ($results as $result) {
            if ($result !== null && !$result instanceof ItemStack) {
                throw new InvalidArgumentException('Brewing results must contain item stacks or null.');
            }
            $validated[] = $result;
        }

        return $validated;
    }
}
