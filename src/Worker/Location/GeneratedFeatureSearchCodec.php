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

namespace Bedriox\Server\Worker\Location;

use Bedriox\Api\World\DimensionType;
use Bedriox\Server\World\Generation\Structure\Location\LocatedFeature;
use InvalidArgumentException;
use JsonException;

final class GeneratedFeatureSearchCodec
{
    public const int MAXIMUM_REQUEST_BYTES = 2_048;
    public const int MAXIMUM_RESULT_BYTES = 512;

    public function encodeRequest(GeneratedFeatureSearchRequest $request): string
    {
        return json_encode([
            'schema' => 1,
            'mode' => $request->mode->value,
            'dimension' => $request->dimension->value,
            'identifier' => $request->identifier,
            'seed' => $request->seed,
            'origin' => [$request->originX, $request->originY, $request->originZ],
            'excluded' => $request->excludedLocations,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function decodeRequest(string $payload): GeneratedFeatureSearchRequest
    {
        $value = $this->decode($payload, self::MAXIMUM_REQUEST_BYTES);
        $mode = is_string($value['mode'] ?? null) ? GeneratedFeatureSearchMode::tryFrom($value['mode']) : null;
        $dimension = is_string($value['dimension'] ?? null) ? DimensionType::tryFrom($value['dimension']) : null;
        $origin = $value['origin'] ?? null;
        $excluded = $value['excluded'] ?? null;
        if (($value['schema'] ?? null) !== 1 || $mode === null || $dimension === null
            || !is_string($value['identifier'] ?? null) || !is_int($value['seed'] ?? null)
            || !is_array($origin) || count($origin) !== 3
            || !is_int($origin[0] ?? null) || !is_int($origin[1] ?? null) || !is_int($origin[2] ?? null)
            || !is_array($excluded) || !array_is_list($excluded)) {
            throw new InvalidArgumentException('Generated feature search request is invalid.');
        }
        $validatedExcluded = [];
        foreach ($excluded as $location) {
            if (!is_string($location)) {
                throw new InvalidArgumentException('Generated feature search exclusion is invalid.');
            }
            $validatedExcluded[] = $location;
        }

        return new GeneratedFeatureSearchRequest(
            $mode,
            $dimension,
            $value['identifier'],
            $value['seed'],
            $origin[0],
            $origin[1],
            $origin[2],
            $validatedExcluded,
        );
    }

    public function encodeResult(?LocatedFeature $feature): string
    {
        return json_encode([
            'schema' => 1,
            'feature' => $feature === null ? null : [
                'identifier' => $feature->identifier,
                'x' => $feature->x,
                'y' => $feature->y,
                'z' => $feature->z,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function decodeResult(string $payload): ?LocatedFeature
    {
        $value = $this->decode($payload, self::MAXIMUM_RESULT_BYTES);
        $feature = $value['feature'] ?? null;
        if (($value['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Generated feature search result is invalid.');
        }
        if ($feature === null) {
            return null;
        }
        if (!is_array($feature) || !is_string($feature['identifier'] ?? null)
            || !is_int($feature['x'] ?? null) || !is_int($feature['y'] ?? null) || !is_int($feature['z'] ?? null)) {
            throw new InvalidArgumentException('Generated feature search result is invalid.');
        }

        return new LocatedFeature(
            $feature['identifier'],
            $feature['x'],
            $feature['y'],
            $feature['z'],
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $payload, int $maximumBytes): array
    {
        if ($payload === '' || strlen($payload) > $maximumBytes) {
            throw new InvalidArgumentException('Generated feature search payload exceeds its bound.');
        }
        try {
            $value = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('Generated feature search payload is invalid JSON.', previous: $failure);
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Generated feature search payload must be an object.');
        }

        $document = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Generated feature search payload must be an object.');
            }
            $document[$key] = $item;
        }

        return $document;
    }
}
