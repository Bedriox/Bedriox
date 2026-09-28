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

namespace Bedriox\Server\Persistence;

use JsonException;
use RuntimeException;

/** Explicit value-only wire codec; PHP object serialization is deliberately forbidden. */
final readonly class PersistenceIpcCodec
{
    public function __construct(private int $maximumPayloadBytes)
    {
        if ($maximumPayloadBytes < 1 || $maximumPayloadBytes > 33_554_432) {
            throw new \InvalidArgumentException('Persistence IPC payload limit is invalid.');
        }
    }

    public function encodeRequest(PersistenceWriteRequest $request): string
    {
        if (strlen($request->payload) > $this->maximumPayloadBytes) {
            throw new RuntimeException('Persistence request payload exceeds its IPC limit.');
        }

        return $this->encode([
            'id' => $request->id,
            'key' => $request->key,
            'kind' => 'write',
            'payload' => base64_encode($request->payload),
            'revision' => $request->revision,
            'sequence' => $request->sequence,
            'version' => 1,
        ]);
    }

    public function decodeRequest(string $encoded): PersistenceWriteRequest
    {
        $message = $this->decode($encoded);
        if (array_keys($message) !== ['id', 'key', 'kind', 'payload', 'revision', 'sequence', 'version']
            || $message['kind'] !== 'write' || $message['version'] !== 1
            || !is_int($message['id']) || !is_string($message['key']) || !is_string($message['payload'])
            || !is_int($message['revision']) || !is_int($message['sequence'])) {
            throw new RuntimeException('Persistence request IPC message has an invalid shape.');
        }
        $payload = base64_decode($message['payload'], true);
        if (!is_string($payload) || strlen($payload) > $this->maximumPayloadBytes) {
            throw new RuntimeException('Persistence request IPC payload is invalid.');
        }

        return new PersistenceWriteRequest(
            $message['id'],
            $message['sequence'],
            $message['key'],
            $message['revision'],
            $payload,
        );
    }

    public function encodeCompletion(PersistenceWriteCompletion $completion): string
    {
        return $this->encode([
            'failure_code' => $completion->failureCode,
            'id' => $completion->requestId,
            'key' => $completion->key,
            'kind' => 'completion',
            'revision' => $completion->revision,
            'successful' => $completion->successful,
            'version' => 1,
        ]);
    }

    public function decodeCompletion(string $encoded): PersistenceWriteCompletion
    {
        $message = $this->decode($encoded);
        if (array_keys($message) !== ['failure_code', 'id', 'key', 'kind', 'revision', 'successful', 'version']
            || $message['kind'] !== 'completion' || $message['version'] !== 1
            || (!is_string($message['failure_code']) && $message['failure_code'] !== null)
            || !is_int($message['id']) || !is_string($message['key'])
            || !is_int($message['revision']) || !is_bool($message['successful'])
            || (is_string($message['failure_code']) && (strlen($message['failure_code']) > 128
                || preg_match('/^[a-z][a-z0-9_.-]*$/D', $message['failure_code']) !== 1))) {
            throw new RuntimeException('Persistence completion IPC message has an invalid shape.');
        }
        if ($message['successful'] && $message['failure_code'] !== null) {
            throw new RuntimeException('Successful persistence completion cannot contain a failure code.');
        }

        return new PersistenceWriteCompletion(
            $message['id'],
            $message['key'],
            $message['revision'],
            $message['successful'],
            $message['failure_code'],
        );
    }

    /** @param array<string, bool|int|string|null> $message */
    private function encode(array $message): string
    {
        try {
            return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new RuntimeException('Persistence IPC message could not be encoded.', previous: $error);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $encoded): array
    {
        $maximumEncodedBytes = (int) ceil($this->maximumPayloadBytes * 4 / 3) + 2_048;
        if (strlen($encoded) > $maximumEncodedBytes) {
            throw new RuntimeException('Persistence IPC message exceeds its size limit.');
        }
        try {
            $message = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Persistence IPC message is malformed.', previous: $error);
        }
        if (!is_array($message) || array_is_list($message)) {
            throw new RuntimeException('Persistence IPC message must be an object.');
        }

        $result = [];
        foreach ($message as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Persistence IPC message contains a non-string field name.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
