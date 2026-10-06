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
use InvalidArgumentException;
use JsonException;

final class UpdateTaskCodec
{
    public const int MAXIMUM_REQUEST_BYTES = 4_096;
    public const int MAXIMUM_RESPONSE_BYTES = 32_768;

    public function encodeRequest(UpdateChannel $channel, ?string $etag, ?string $lastModified): string
    {
        return json_encode([
            'schema' => 1,
            'channel' => $channel->value,
            'etag' => self::header($etag),
            'last_modified' => self::header($lastModified),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{channel: UpdateChannel, etag: ?string, last_modified: ?string} */
    public function decodeRequest(string $payload): array
    {
        $value = $this->decode($payload, self::MAXIMUM_REQUEST_BYTES);
        $channel = is_string($value['channel'] ?? null) ? UpdateChannel::tryFrom($value['channel']) : null;
        if (($value['schema'] ?? null) !== 1 || $channel === null) {
            throw new InvalidArgumentException('Update task request is invalid.');
        }

        return [
            'channel' => $channel,
            'etag' => self::header($value['etag'] ?? null),
            'last_modified' => self::header($value['last_modified'] ?? null),
        ];
    }

    /** @param array{status: int, etag: ?string, last_modified: ?string, body: string} $response */
    public function encodeResponse(array $response): string
    {
        return json_encode(['schema' => 1] + $response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{status: int, etag: ?string, last_modified: ?string, body: string} */
    public function decodeResponse(string $payload): array
    {
        $value = $this->decode($payload, self::MAXIMUM_RESPONSE_BYTES);
        $status = $value['status'] ?? null;
        $body = $value['body'] ?? null;
        if (($value['schema'] ?? null) !== 1 || !is_int($status) || !in_array($status, [200, 304], true)
            || !is_string($body) || strlen($body) > 16_384 || ($status === 304 && $body !== '')) {
            throw new InvalidArgumentException('Update task response is invalid.');
        }

        return [
            'status' => $status,
            'etag' => self::header($value['etag'] ?? null),
            'last_modified' => self::header($value['last_modified'] ?? null),
            'body' => $body,
        ];
    }

    /** @return array<string, mixed> */
    private function decode(string $payload, int $maximumBytes): array
    {
        if ($payload === '' || strlen($payload) > $maximumBytes) {
            throw new InvalidArgumentException('Update task payload exceeds its bound.');
        }
        try {
            $value = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('Update task payload is invalid JSON.', previous: $failure);
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Update task payload must be an object.');
        }

        $document = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Update task payload must be an object.');
            }
            $document[$key] = $item;
        }

        return $document;
    }

    private static function header(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 512 || preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Update cache header is invalid.');
        }

        return $value;
    }
}
