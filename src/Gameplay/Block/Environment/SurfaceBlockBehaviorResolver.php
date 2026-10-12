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

namespace Bedriox\Server\Gameplay\Block\Environment;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Pure bounded rules for grass and mycelium decay and spread. */
final readonly class SurfaceBlockBehaviorResolver
{
    private InternalBlockStateId $dirt;
    private InternalBlockStateId $grass;
    private InternalBlockStateId $mycelium;

    public function __construct(BlockStateRegistry $states)
    {
        $this->dirt = $states->internalId(CanonicalBlockState::from('minecraft:dirt'));
        $this->grass = $states->internalId(CanonicalBlockState::from('minecraft:grass_block'));
        $this->mycelium = $states->internalId(CanonicalBlockState::from('minecraft:mycelium'));
    }

    /**
     * @param list<mixed> $candidates Caller-selected probes in deterministic order.
     * @return list<EnvironmentBlockTransition>
     */
    public function resolve(
        BlockPosition $sourcePosition,
        CanonicalBlockState $source,
        bool $growthBlockedAbove,
        array $candidates,
    ): array {
        if (count($candidates) > 4) {
            throw new InvalidArgumentException('A surface random tick may inspect at most four spread candidates.');
        }
        $validatedCandidates = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof SurfaceSpreadCandidate) {
                throw new InvalidArgumentException('Surface spread candidates must be immutable candidate values.');
            }
            $validatedCandidates[] = $candidate;
        }

        $sourceIdentifier = $source->identifier();
        if ($sourceIdentifier !== 'minecraft:grass_block' && $sourceIdentifier !== 'minecraft:mycelium') {
            return [];
        }
        if ($growthBlockedAbove) {
            return [new EnvironmentBlockTransition($sourcePosition, $this->dirt)];
        }

        $target = $sourceIdentifier === 'minecraft:grass_block' ? $this->grass : $this->mycelium;
        $maximumAttempts = $sourceIdentifier === 'minecraft:grass_block' ? 4 : 1;
        $transitions = [];
        foreach (array_slice($validatedCandidates, 0, $maximumAttempts) as $candidate) {
            if ($candidate->state->identifier() === 'minecraft:dirt' && !$candidate->growthBlockedAbove) {
                $transitions[] = new EnvironmentBlockTransition($candidate->position, $target);
            }
        }

        return $transitions;
    }
}
