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

namespace Bedriox\Api\Entity;

use InvalidArgumentException;
use JsonException;

/** Immutable protocol-independent player-shaped actor skin. */
final readonly class Skin
{
    /** @var list<SkinAnimation> */
    public array $animations;

    /** @param array<array-key, mixed> $animations */
    public function __construct(
        public string $id,
        public int $width,
        public int $height,
        public string $rgba,
        public string $resourcePatchJson,
        public string $geometryJson = '',
        public int $capeWidth = 0,
        public int $capeHeight = 0,
        public string $capeRgba = '',
        public string $capeId = '',
        array $animations = [],
        public string $animationDataJson = '',
        public string $geometryEngineVersion = '',
        public string $armSize = '',
        public string $skinColor = '',
        public bool $premium = false,
        public bool $capeOnClassic = false,
    ) {
        self::boundedText($id, 256, 'Skin ID', false);
        self::image($width, $height, $rgba, 262_144, false, 'Skin');
        if ($width < 64 || $height < 32) {
            throw new InvalidArgumentException('Skin dimensions are below the Bedrock minimum.');
        }
        self::image($capeWidth, $capeHeight, $capeRgba, 65_536, true, 'Cape');
        self::boundedText($capeId, 256, 'Cape ID');
        self::boundedText($geometryEngineVersion, 128, 'Geometry engine version');
        self::boundedText($armSize, 32, 'Skin arm size');
        self::boundedText($skinColor, 32, 'Skin color');
        if (!in_array(strtolower($armSize), ['', 'slim', 'wide'], true)) {
            throw new InvalidArgumentException('Skin arm size must be empty, slim, or wide.');
        }
        if ($skinColor !== '' && preg_match('/^#[0-9a-f]{1,8}$/Di', $skinColor) !== 1) {
            throw new InvalidArgumentException('Skin color must be empty or a hexadecimal color.');
        }
        if (!array_is_list($animations) || count($animations) > 32) {
            throw new InvalidArgumentException('Skin animation count exceeds its limit.');
        }
        foreach ($animations as $animation) {
            if (!$animation instanceof SkinAnimation) {
                throw new InvalidArgumentException('Skin animations must contain SkinAnimation values.');
            }
        }
        $this->animations = $animations;
        self::json($resourcePatchJson, 65_536, 'Skin resource patch', false);
        self::json($geometryJson, 65_536, 'Skin geometry');
        self::json($animationDataJson, 65_536, 'Skin animation data');
        $patch = json_decode($resourcePatchJson, true, 16);
        $geometry = is_array($patch) && is_array($patch['geometry'] ?? null)
            ? ($patch['geometry']['default'] ?? null)
            : null;
        if (!is_string($geometry) || $geometry === '' || strlen($geometry) > 256) {
            throw new InvalidArgumentException('Skin resource patch must name a bounded default geometry.');
        }
    }

    private static function image(
        int $width,
        int $height,
        string $bytes,
        int $maximumBytes,
        bool $allowEmpty,
        string $field,
    ): void {
        if ($allowEmpty && $width === 0 && $height === 0 && $bytes === '') {
            return;
        }
        if ($width < 1 || $height < 1 || $width > 256 || $height > 256
            || strlen($bytes) !== $width * $height * 4 || strlen($bytes) > $maximumBytes) {
            throw new InvalidArgumentException($field . ' dimensions must match bounded RGBA data.');
        }
    }

    private static function boundedText(string $value, int $maximumBytes, string $field, bool $allowEmpty = true): void
    {
        if ((!$allowEmpty && $value === '') || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException($field . ' must be valid UTF-8 and bounded.');
        }
    }

    private static function json(string $value, int $maximumBytes, string $field, bool $allowEmpty = true): void
    {
        if ($allowEmpty && $value === '') {
            return;
        }
        if (strlen($value) > $maximumBytes) {
            throw new InvalidArgumentException($field . ' exceeds its byte limit.');
        }
        try {
            json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException($field . ' must be valid JSON.', previous: $error);
        }
    }
}
