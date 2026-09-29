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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Data\ContainerMix;
use Bedriox\Data\PotionMix;

/** Immutable indexed projection of the admitted Bedrock brewing transitions. */
final readonly class BrewingRecipeCatalog
{
    /** @var array<string, BrewingResult> */
    private array $recipes;

    /**
     * @param list<ContainerMix> $containerMixes
     * @param list<PotionMix> $potionMixes
     */
    public function __construct(array $containerMixes, array $potionMixes)
    {
        $recipes = [];
        foreach ($containerMixes as $mix) {
            self::put(
                $recipes,
                self::key($mix->inputItemIdentifier(), 0, $mix->reagentItemIdentifier(), 0),
                new BrewingResult($mix->outputItemIdentifier(), 0),
            );
        }
        foreach ($potionMixes as $mix) {
            self::put(
                $recipes,
                self::key(
                    $mix->inputItemIdentifier(),
                    $mix->inputMetadata(),
                    $mix->reagentItemIdentifier(),
                    $mix->reagentMetadata(),
                ),
                new BrewingResult($mix->outputItemIdentifier(), $mix->outputMetadata()),
            );
        }
        $this->recipes = $recipes;
    }

    public function match(
        string $inputIdentifier,
        int $inputAuxValue,
        string $reagentIdentifier,
        int $reagentAuxValue = 0,
    ): ?BrewingResult {
        $exact = $this->recipes[self::key($inputIdentifier, $inputAuxValue, $reagentIdentifier, $reagentAuxValue)] ?? null;
        if ($exact !== null) {
            return $exact;
        }
        $containerChange = $this->recipes[self::key($inputIdentifier, 0, $reagentIdentifier, 0)] ?? null;

        return $containerChange === null
            ? null
            : new BrewingResult($containerChange->itemIdentifier, $inputAuxValue);
    }

    public function count(): int
    {
        return count($this->recipes);
    }

    /** @param array<string, BrewingResult> $recipes */
    private static function put(array &$recipes, string $key, BrewingResult $result): void
    {
        if (isset($recipes[$key])) {
            throw new \InvalidArgumentException('Brewing data contains a duplicate transition.');
        }
        $recipes[$key] = $result;
    }

    private static function key(string $input, int $inputMeta, string $reagent, int $reagentMeta): string
    {
        return $input . "\0" . $inputMeta . "\0" . $reagent . "\0" . $reagentMeta;
    }
}
