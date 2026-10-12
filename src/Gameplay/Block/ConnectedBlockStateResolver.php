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

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Resolves canonical four-way connection properties after bounded neighbor classification. */
final readonly class ConnectedBlockStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    /**
     * @param array<string, mixed> $connections
     * @param array<string, mixed> $tallConnections
     */
    public function resolve(
        CanonicalBlockState $state,
        array $connections,
        array $tallConnections = [],
        bool $forceWallPost = false,
    ): InternalBlockStateId {
        foreach (['north', 'east', 'south', 'west'] as $direction) {
            if (!array_key_exists($direction, $connections) || !is_bool($connections[$direction])) {
                throw new InvalidArgumentException('Connected block state requires four boolean horizontal connections.');
            }
            if (isset($tallConnections[$direction]) && !is_bool($tallConnections[$direction])) {
                throw new InvalidArgumentException('Tall wall connections must be boolean values.');
            }
        }
        $properties = $state->properties();
        if (array_key_exists('wall_post_bit', $properties)) {
            foreach (['north', 'east', 'south', 'west'] as $direction) {
                $connected = $connections[$direction] === true;
                $properties['wall_connection_type_' . $direction] = !$connected
                    ? 'none'
                    : (($tallConnections[$direction] ?? false) ? 'tall' : 'short');
            }
            $north = $connections['north'];
            $east = $connections['east'];
            $south = $connections['south'];
            $west = $connections['west'];
            $straight = ($north && $south && !$east && !$west) || ($east && $west && !$north && !$south);
            $properties['wall_post_bit'] = $forceWallPost || !$straight ? 1 : 0;
        } elseif (array_key_exists('minecraft:connection_north', $properties)) {
            foreach (['north', 'east', 'south', 'west'] as $direction) {
                $properties['minecraft:connection_' . $direction] = $connections[$direction] === true ? 1 : 0;
            }
        } else {
            throw new InvalidArgumentException('Block state does not expose horizontal connection properties.');
        }

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }
}
