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

namespace Bedriox\Server\Gameplay\Trading;

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;
use JsonException;

/** Bounded persistence representation for canonical server-owned villager offers. */
final class VillagerTradeCodec
{
    public const int MAXIMUM_BYTES = 48_000;

    public static function encode(VillagerTradeList $trades): string
    {
        $offers = [];
        foreach ($trades->offers as $offer) {
            $offers[] = [
                'key' => $offer->key,
                'level' => $offer->level->value,
                'first' => self::encodeStack($offer->firstInput),
                'second' => $offer->secondInput === null ? null : self::encodeStack($offer->secondInput),
                'result' => self::encodeStack($offer->result),
                'uses' => $offer->uses,
                'maximumUses' => $offer->maximumUses,
                'villagerExperience' => $offer->villagerExperience,
                'rewardsPlayerExperience' => $offer->rewardsPlayerExperience,
                'priceMultiplier' => $offer->priceMultiplier,
                'demand' => $offer->demand,
                'specialPrice' => $offer->specialPrice,
            ];
        }
        $encoded = json_encode(['generation' => $trades->generation, 'offers' => $offers], JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Encoded villager trades exceed their byte limit.');
        }

        return $encoded;
    }

    public static function decode(string $encoded): VillagerTradeList
    {
        if (strlen($encoded) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Persisted villager trades exceed their byte limit.');
        }
        try {
            $data = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted villager trades are malformed.', previous: $error);
        }
        if (!is_array($data) || array_keys($data) !== ['generation', 'offers']
            || !is_int($data['generation']) || !is_array($data['offers']) || !array_is_list($data['offers'])) {
            throw new InvalidArgumentException('Persisted villager trades are malformed.');
        }
        $offers = [];
        foreach ($data['offers'] as $entry) {
            if (!is_array($entry) || array_keys($entry) !== [
                'key', 'level', 'first', 'second', 'result', 'uses', 'maximumUses', 'villagerExperience',
                'rewardsPlayerExperience', 'priceMultiplier', 'demand', 'specialPrice',
            ] || !is_string($entry['key']) || !is_int($entry['level']) || !is_array($entry['first'])
                || ($entry['second'] !== null && !is_array($entry['second'])) || !is_array($entry['result'])
                || !is_int($entry['uses']) || !is_int($entry['maximumUses'])
                || !is_int($entry['villagerExperience']) || !is_bool($entry['rewardsPlayerExperience'])
                || (!is_int($entry['priceMultiplier']) && !is_float($entry['priceMultiplier']))
                || !is_int($entry['demand']) || !is_int($entry['specialPrice'])) {
                throw new InvalidArgumentException('Persisted villager trade entry is malformed.');
            }
            $level = VillagerLevel::tryFrom($entry['level']);
            if ($level === null) {
                throw new InvalidArgumentException('Persisted villager trade level is unsupported.');
            }
            $offers[] = new VillagerTradeOffer(
                $entry['key'],
                $level,
                self::decodeStack($entry['first']),
                $entry['second'] === null ? null : self::decodeStack($entry['second']),
                self::decodeStack($entry['result']),
                $entry['uses'],
                $entry['maximumUses'],
                $entry['villagerExperience'],
                $entry['rewardsPlayerExperience'],
                (float) $entry['priceMultiplier'],
                $entry['demand'],
                $entry['specialPrice'],
            );
        }

        return new VillagerTradeList($offers, $data['generation']);
    }

    /** @return array{identifier: string, count: int, damage: int, nbt: ?string, auxValue: int} */
    private static function encodeStack(ItemStack $stack): array
    {
        return [
            'identifier' => $stack->identifier,
            'count' => $stack->count,
            'damage' => $stack->damage,
            'nbt' => $stack->nbt === null ? null : base64_encode($stack->nbt->toBinary()),
            'auxValue' => $stack->auxValue,
        ];
    }

    /** @param array<mixed> $data */
    private static function decodeStack(array $data): ItemStack
    {
        if (array_keys($data) !== ['identifier', 'count', 'damage', 'nbt', 'auxValue']
            || !is_string($data['identifier']) || !is_int($data['count']) || !is_int($data['damage'])
            || ($data['nbt'] !== null && !is_string($data['nbt'])) || !is_int($data['auxValue'])) {
            throw new InvalidArgumentException('Persisted villager trade item is malformed.');
        }
        $nbt = null;
        if ($data['nbt'] !== null) {
            $binary = base64_decode($data['nbt'], true);
            if ($binary === false) {
                throw new InvalidArgumentException('Persisted villager trade item NBT is malformed.');
            }
            $nbt = ItemNbt::fromBinary($binary);
        }

        return new ItemStack($data['identifier'], $data['count'], $data['damage'], $nbt, $data['auxValue']);
    }
}
