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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use JsonException;

/** Bounded persistence for explicit End gateway destinations. */
final readonly class EndGatewayStateRepository
{
    private const string NAMESPACE = 'end_gateways';
    private const int MAXIMUM_BYTES = 16_384;

    public function __construct(private TransientEntityPersistenceStore $store) {}

    /** @return list<EndGatewayLink> */
    public function load(): array
    {
        $payload = $this->store->loadTransientEntities(self::NAMESPACE);
        if ($payload === null) {
            return [];
        }
        if (strlen($payload) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Persisted End gateway state exceeds its size limit.');
        }
        try {
            $records = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted End gateway state is malformed.', previous: $error);
        }
        if (!is_array($records) || !array_is_list($records) || count($records) > EndGatewayPlanner::GATEWAY_COUNT) {
            throw new InvalidArgumentException('Persisted End gateway records are invalid.');
        }
        $links = [];
        $slots = [];
        foreach ($records as $record) {
            if (!is_array($record) || array_keys($record) !== ['slot', 'inner', 'outer']) {
                throw new InvalidArgumentException('Persisted End gateway record shape is invalid.');
            }
            $slot = $record['slot'] ?? null;
            if (!is_int($slot) || isset($slots[$slot])) {
                throw new InvalidArgumentException('Persisted End gateway slot is invalid or duplicated.');
            }
            $slots[$slot] = true;
            $links[] = new EndGatewayLink(
                $slot,
                self::position($record['inner'] ?? null),
                self::position($record['outer'] ?? null),
            );
        }

        return $links;
    }

    /** @param list<EndGatewayLink> $links */
    public function save(array $links): void
    {
        if (count($links) > EndGatewayPlanner::GATEWAY_COUNT) {
            throw new InvalidArgumentException('End gateway link count exceeds its limit.');
        }
        $records = array_map(static fn(EndGatewayLink $link): array => [
            'slot' => $link->slot,
            'inner' => [$link->inner->x, $link->inner->y, $link->inner->z],
            'outer' => [$link->outer->x, $link->outer->y, $link->outer->z],
        ], $links);
        try {
            $payload = json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('End gateway state could not be encoded.', previous: $error);
        }
        if (strlen($payload) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('End gateway state exceeds its size limit.');
        }
        $this->store->saveTransientEntities(self::NAMESPACE, $payload);
    }

    private static function position(mixed $value): BlockPosition
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3
            || !is_int($value[0]) || !is_int($value[1]) || !is_int($value[2])) {
            throw new InvalidArgumentException('Persisted End gateway position is invalid.');
        }

        return new BlockPosition($value[0], $value[1], $value[2]);
    }
}
