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

namespace Bedriox\Server\Update;

use Bedriox\Api\Update\UpdateChannel;
use Bedriox\Api\Update\UpdateInfo;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final class UpdateDocumentDecoder
{
    public function decode(string $body, UpdateChannel $expectedChannel): UpdateInfo
    {
        if ($body === '' || strlen($body) > 16_384) {
            throw new InvalidArgumentException('Update document exceeds its bound.');
        }
        try {
            $value = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('Update document is invalid JSON.', previous: $failure);
        }
        if (!is_array($value) || ($value['schema'] ?? null) !== 1 || ($value['product'] ?? null) !== 'bedriox'
            || ($value['channel'] ?? null) !== $expectedChannel->value
            || !is_string($value['version'] ?? null) || !is_string($value['published_at'] ?? null)) {
            throw new InvalidArgumentException('Update document identity is invalid.');
        }
        $version = SemanticVersion::parse($value['version']);
        $versionChannel = $version->channel();
        if ($expectedChannel === UpdateChannel::STABLE && $versionChannel !== UpdateChannel::STABLE) {
            throw new InvalidArgumentException('Stable update channel returned a prerelease.');
        }
        try {
            $published = new DateTimeImmutable($value['published_at']);
        } catch (\Throwable $failure) {
            throw new InvalidArgumentException('Update publication date is invalid.', previous: $failure);
        }

        return new UpdateInfo(
            $expectedChannel,
            $version->value,
            $published,
            self::url($value['release_url'] ?? null, '/Bedriox/Bedriox/releases/tag/'),
            self::url($value['download_url'] ?? null, '/Bedriox/Bedriox/releases/download/'),
        );
    }

    private static function url(mixed $value, string $pathPrefix): string
    {
        if (!is_string($value) || strlen($value) > 2_048
            || parse_url($value, PHP_URL_SCHEME) !== 'https'
            || strtolower((string) parse_url($value, PHP_URL_HOST)) !== 'github.com'
            || !str_starts_with((string) parse_url($value, PHP_URL_PATH), $pathPrefix)) {
            throw new InvalidArgumentException('Update document contains an unsafe URL.');
        }

        return $value;
    }
}
