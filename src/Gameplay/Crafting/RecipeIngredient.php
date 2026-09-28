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

namespace Bedriox\Server\Gameplay\Crafting;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Player\InventoryStack;
use InvalidArgumentException;

/** One bounded server-owned ingredient alternative set. */
final readonly class RecipeIngredient
{
    public const int MAXIMUM_ALTERNATIVES = 512;

    /** @var list<string> */
    public array $identifiers;

    /** @param list<string> $identifiers */
    public function __construct(
        array $identifiers,
        public int $count = 1,
        public ?int $auxValue = null,
        public ?int $damage = null,
        public ?ItemNbt $nbt = null,
    ) {
        if ($identifiers === [] || count($identifiers) > self::MAXIMUM_ALTERNATIVES) {
            throw new InvalidArgumentException('Recipe ingredient alternatives are outside their supported bounds.');
        }
        $unique = [];
        foreach ($identifiers as $identifier) {
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
                throw new InvalidArgumentException('Recipe ingredient identifier must be canonical and namespaced.');
            }
            $unique[$identifier] = true;
        }
        if ($count < 1 || $count > 64) {
            throw new InvalidArgumentException('Recipe ingredient count must be between 1 and 64.');
        }
        if ($auxValue !== null && ($auxValue < 0 || $auxValue > 32_767)) {
            throw new InvalidArgumentException('Recipe ingredient auxiliary value is outside its supported range.');
        }
        if ($damage !== null && ($damage < 0 || $damage > 65_535)) {
            throw new InvalidArgumentException('Recipe ingredient damage is outside its supported range.');
        }

        $this->identifiers = array_keys($unique);
    }

    public static function exact(
        string $identifier,
        int $count = 1,
        ?int $auxValue = null,
        ?int $damage = null,
        ?ItemNbt $nbt = null,
    ): self {
        return new self([$identifier], $count, $auxValue, $damage, $nbt);
    }

    public function accepts(InventoryStack $stack, int $repetitions = 1): bool
    {
        if ($repetitions < 1 || $repetitions > 255) {
            return false;
        }

        return $stack->count >= $this->count * $repetitions && $this->matches($stack);
    }

    /** Matches the authoritative stack content without considering its available count. */
    public function matches(InventoryStack $stack): bool
    {
        if (!in_array($stack->identifier, $this->identifiers, true)
            || ($this->auxValue !== null && $stack->auxValue !== $this->auxValue)
            || ($this->damage !== null && $stack->damage !== $this->damage)) {
            return false;
        }

        return $this->nbt === null
            || ($stack->nbt !== null && $this->nbt->equals($stack->nbt));
    }
}
