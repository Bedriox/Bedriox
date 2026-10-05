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

namespace Bedriox\Server\Gameplay\Portal;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\World;
use InvalidArgumentException;

/** Authoritative End Portal frame recognition and bounded portal mutations. */
final class EndPortalSystem
{
    /** @var list<array{int, int, string}> */
    private const array FRAME_BLOCKS = [
        [-1, -2, 'south'], [0, -2, 'south'], [1, -2, 'south'],
        [-1, 2, 'north'], [0, 2, 'north'], [1, 2, 'north'],
        [-2, -1, 'east'], [-2, 0, 'east'], [-2, 1, 'east'],
        [2, -1, 'west'], [2, 0, 'west'], [2, 1, 'west'],
    ];

    private readonly InternalBlockStateId $air;
    private readonly InternalBlockStateId $portal;

    public function __construct(
        private readonly World $world,
        private readonly BlockStateRegistry $states,
    ) {
        $this->air = $this->resolve('minecraft:air');
        $this->portal = $this->resolve('minecraft:end_portal');
    }

    public function filledFrameState(BlockPosition $position): ?InternalBlockStateId
    {
        $current = $this->world->loadedBlockStateAt($position->x, $position->y, $position->z);
        if ($current === null) {
            return null;
        }
        $state = $this->states->state($current);
        $properties = $state->properties();
        if ($state->identifier() !== 'minecraft:end_portal_frame'
            || ($properties['end_portal_eye_bit'] ?? null) !== 0
            || !is_string($properties['minecraft:cardinal_direction'] ?? null)) {
            return null;
        }
        $properties['end_portal_eye_bit'] = 1;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    public function find(BlockPosition $framePosition): ?EndPortalFrame
    {
        $state = $this->canonicalAt($framePosition);
        if ($state === null || !$this->isFilledFrame($state)) {
            return null;
        }
        $direction = $state->properties()['minecraft:cardinal_direction'] ?? null;
        foreach ($this->possibleCenters($framePosition, is_string($direction) ? $direction : '') as $center) {
            if ($this->isComplete($center)) {
                return new EndPortalFrame($center);
            }
        }

        return null;
    }

    /** @return list<PortalBlockMutation> */
    public function activate(EndPortalFrame $frame): array
    {
        $mutations = [];
        foreach ($frame->interior() as $position) {
            $previous = $this->world->setBlockState($position->x, $position->y, $position->z, $this->portal);
            if ($previous->value !== $this->portal->value) {
                $mutations[] = new PortalBlockMutation($position, $previous, $this->portal);
            }
        }

        return $mutations;
    }

    /** @return list<PortalBlockMutation> */
    public function invalidateNear(BlockPosition $changed): array
    {
        $mutations = [];
        for ($centerX = $changed->x - 2; $centerX <= $changed->x + 2; ++$centerX) {
            for ($centerZ = $changed->z - 2; $centerZ <= $changed->z + 2; ++$centerZ) {
                $center = new BlockPosition($centerX, $changed->y, $centerZ);
                if ($this->isComplete($center)) {
                    continue;
                }
                foreach ((new EndPortalFrame($center))->interior() as $position) {
                    $previous = $this->world->loadedBlockStateAt($position->x, $position->y, $position->z);
                    if ($previous === null || $this->states->state($previous)->identifier() !== 'minecraft:end_portal') {
                        continue;
                    }
                    $removed = $this->world->setBlockState($position->x, $position->y, $position->z, $this->air);
                    $mutations[] = new PortalBlockMutation($position, $removed, $this->air);
                }
            }
        }

        return $mutations;
    }

    private function isComplete(BlockPosition $center): bool
    {
        foreach (self::FRAME_BLOCKS as [$x, $z, $direction]) {
            $state = $this->canonicalAt(new BlockPosition($center->x + $x, $center->y, $center->z + $z));
            if ($state === null || !$this->isFilledFrame($state)
                || ($state->properties()['minecraft:cardinal_direction'] ?? null) !== $direction) {
                return false;
            }
        }

        return true;
    }

    /** @return list<BlockPosition> */
    private function possibleCenters(BlockPosition $frame, string $direction): array
    {
        $offsets = match ($direction) {
            'north' => [[-1, -2], [0, -2], [1, -2]],
            'south' => [[-1, 2], [0, 2], [1, 2]],
            'west' => [[-2, -1], [-2, 0], [-2, 1]],
            'east' => [[2, -1], [2, 0], [2, 1]],
            default => [],
        };

        return array_map(
            static fn(array $offset): BlockPosition => new BlockPosition(
                $frame->x + $offset[0],
                $frame->y,
                $frame->z + $offset[1],
            ),
            $offsets,
        );
    }

    private function canonicalAt(BlockPosition $position): ?CanonicalBlockState
    {
        $state = $this->world->loadedBlockStateAt($position->x, $position->y, $position->z);

        return $state === null ? null : $this->states->state($state);
    }

    private function isFilledFrame(?CanonicalBlockState $state): bool
    {
        return $state?->identifier() === 'minecraft:end_portal_frame'
            && ($state->properties()['end_portal_eye_bit'] ?? null) === 1;
    }

    /** @param array<string, int|string> $properties */
    private function resolve(string $identifier, array $properties = []): InternalBlockStateId
    {
        try {
            return $this->states->internalId(CanonicalBlockState::from($identifier, $properties));
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException("Required End portal block state {$identifier} is unavailable.", previous: $error);
        }
    }
}
