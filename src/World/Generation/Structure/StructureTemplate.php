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

namespace Bedriox\Server\World\Generation\Structure;

use InvalidArgumentException;

/** Bedriox-owned, bounded structure geometry independent from any external template format. */
final readonly class StructureTemplate
{
    private const int MAX_AXIS_SIZE = 128;
    private const int MAX_BLOCKS = 262_144;
    private const int MAX_CONNECTORS = 1_024;
    private const int MAX_MARKERS = 1_024;

    /**
     * @param list<StructureTemplateBlock>  $blocks
     * @param list<StructureConnector>      $connectors
     * @param list<StructureTemplateMarker> $markers
     */
    public function __construct(
        public int $width,
        public int $height,
        public int $depth,
        public array $blocks,
        public array $connectors = [],
        public array $markers = [],
    ) {
        foreach ([$width, $height, $depth] as $size) {
            if ($size < 1 || $size > self::MAX_AXIS_SIZE) {
                throw new InvalidArgumentException('Structure template dimensions must each be between 1 and 128 blocks.');
            }
        }
        if (count($blocks) > self::MAX_BLOCKS || count($connectors) > self::MAX_CONNECTORS
            || count($markers) > self::MAX_MARKERS) {
            throw new InvalidArgumentException('Structure template exceeds its bounded content limit.');
        }

        $occupied = [];
        foreach ($blocks as $block) {
            $this->validateCoordinate($block->coordinate);
            $key = self::coordinateKey($block->coordinate);
            if (isset($occupied[$key])) {
                throw new InvalidArgumentException("Structure template contains duplicate block coordinate $key.");
            }
            $occupied[$key] = true;
        }

        $connectorKeys = [];
        foreach ($connectors as $connector) {
            $this->validateCoordinate($connector->coordinate);
            if (isset($connectorKeys[$connector->key])) {
                throw new InvalidArgumentException("Structure template contains duplicate connector key $connector->key.");
            }
            $connectorKeys[$connector->key] = true;
        }

        $markerKeys = [];
        foreach ($markers as $marker) {
            $this->validateCoordinate($marker->coordinate);
            if (isset($markerKeys[$marker->key])) {
                throw new InvalidArgumentException("Structure template contains duplicate marker key $marker->key.");
            }
            $markerKeys[$marker->key] = true;
        }
    }

    public function place(StructureCoordinate $origin, StructureTransform $transform = new StructureTransform()): StructurePlacement
    {
        $blocks = [];
        foreach ($this->blocks as $block) {
            $blocks[] = new PlacedStructureBlock(
                $transform->coordinate($block->coordinate, $this->width, $this->depth)
                    ->translated($origin->x, $origin->y, $origin->z),
                StructureStateTransformer::transform($block->state, $transform),
            );
        }

        $connectors = [];
        foreach ($this->connectors as $connector) {
            $connectors[] = new PlacedStructureConnector(
                $connector->key,
                $connector->kind,
                $transform->coordinate($connector->coordinate, $this->width, $this->depth)
                    ->translated($origin->x, $origin->y, $origin->z),
                $transform->direction($connector->direction),
            );
        }

        $markers = [];
        foreach ($this->markers as $marker) {
            $markers[] = new PlacedStructureMarker(
                $marker->key,
                $marker->type,
                $marker->identifier,
                $transform->coordinate($marker->coordinate, $this->width, $this->depth)
                    ->translated($origin->x, $origin->y, $origin->z),
                $transform->direction($marker->direction),
                $marker->attributes,
            );
        }

        return new StructurePlacement(
            $transform->width($this->width, $this->depth),
            $this->height,
            $transform->depth($this->width, $this->depth),
            $blocks,
            $connectors,
            $markers,
        );
    }

    public function connect(
        string $connectorKey,
        PlacedStructureConnector $target,
        StructureTransform $transform = new StructureTransform(),
    ): StructurePlacement {
        $connector = $this->connector($connectorKey);
        if ($connector->kind !== $target->kind) {
            throw new InvalidArgumentException('Structure connectors must have matching kinds.');
        }
        $direction = $transform->direction($connector->direction);
        if ($direction !== $target->direction->opposite()) {
            throw new InvalidArgumentException('Structure connector directions do not face each other.');
        }

        $local = $transform->coordinate($connector->coordinate, $this->width, $this->depth);
        $targetCoordinate = $target->coordinate->translated(
            $target->direction->offsetX(),
            0,
            $target->direction->offsetZ(),
        );

        return $this->place(new StructureCoordinate(
            $targetCoordinate->x - $local->x,
            $targetCoordinate->y - $local->y,
            $targetCoordinate->z - $local->z,
        ), $transform);
    }

    private function connector(string $key): StructureConnector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->key === $key) {
                return $connector;
            }
        }

        throw new InvalidArgumentException("Structure template does not contain connector $key.");
    }

    private function validateCoordinate(StructureCoordinate $coordinate): void
    {
        if ($coordinate->x < 0 || $coordinate->x >= $this->width
            || $coordinate->y < 0 || $coordinate->y >= $this->height
            || $coordinate->z < 0 || $coordinate->z >= $this->depth) {
            throw new InvalidArgumentException('Structure template coordinate is outside its declared dimensions.');
        }
    }

    private static function coordinateKey(StructureCoordinate $coordinate): string
    {
        return $coordinate->x . ':' . $coordinate->y . ':' . $coordinate->z;
    }
}
