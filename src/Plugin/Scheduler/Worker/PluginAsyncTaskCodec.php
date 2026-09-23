<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler\Worker;

use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Server\Plugin\PluginArchiveIdentity;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskRequest;
use JsonException;

/** @internal Explicit JSON codec for the isolated plugin-worker boundary. */
final class PluginAsyncTaskCodec
{
    private const int VERSION = 1;

    public function encodeRequest(AsyncTaskRequest $request): string
    {
        return json_encode([
            'version' => self::VERSION,
            'owner' => $request->owner,
            'generation' => $request->ownerGeneration,
            'package' => [
                'path' => $request->packageIdentity->path,
                'owner' => $request->packageIdentity->owner,
                'version' => $request->packageIdentity->version,
                'sha256' => $request->packageIdentity->sha256,
                'signature_type' => $request->packageIdentity->signatureType,
                'signature_hash' => $request->packageIdentity->signatureHash,
            ],
            'class' => $request->taskClass,
            'input' => $request->input->value(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array{owner: string, generation: int, package: PluginArchiveIdentity, class: string, input: AsyncTaskValue} */
    public function decodeRequest(string $payload): array
    {
        try {
            $value = json_decode($payload, true, AsyncTaskValue::MAX_DEPTH + 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new \InvalidArgumentException('Plugin task request is malformed.', previous: $failure);
        }
        if (!is_array($value) || ($value['version'] ?? null) !== self::VERSION
            || !is_string($value['owner'] ?? null) || $value['owner'] === '' || strlen($value['owner']) > 128
            || !is_int($value['generation'] ?? null) || $value['generation'] < 1
            || !is_array($value['package'] ?? null)
            || !is_string($value['class'] ?? null) || $value['class'] === '' || strlen($value['class']) > 512
            || !array_key_exists('input', $value)) {
            throw new \InvalidArgumentException('Plugin task request fields are invalid.');
        }

        $package = $value['package'];
        if (array_keys($package) !== ['path', 'owner', 'version', 'sha256', 'signature_type', 'signature_hash']
            || !is_string($package['path']) || !is_string($package['owner']) || !is_string($package['version'])
            || !is_string($package['sha256']) || !is_string($package['signature_type'])
            || !is_string($package['signature_hash'])) {
            throw new \InvalidArgumentException('Plugin task package identity is invalid.');
        }
        $identity = new PluginArchiveIdentity(
            $package['path'],
            $package['owner'],
            $package['version'],
            $package['sha256'],
            $package['signature_type'],
            $package['signature_hash'],
        );
        if (strcasecmp($identity->owner, $value['owner']) !== 0) {
            throw new \InvalidArgumentException('Plugin task owner does not match its admitted package identity.');
        }

        return [
            'owner' => $value['owner'],
            'generation' => $value['generation'],
            'package' => $identity,
            'class' => $value['class'],
            'input' => new AsyncTaskValue($value['input']),
        ];
    }

    public function encodeSuccess(string $owner, int $generation, AsyncTaskValue $result): string
    {
        return json_encode([
            'version' => self::VERSION,
            'owner' => $owner,
            'generation' => $generation,
            'success' => true,
            'result' => $result->value(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function encodeFailure(string $owner, int $generation, string $type): string
    {
        return json_encode([
            'version' => self::VERSION,
            'owner' => $owner,
            'generation' => $generation,
            'success' => false,
            'failure' => $type,
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{owner: string, generation: int, result?: AsyncTaskValue, failure?: string} */
    public function decodeResult(string $payload): array
    {
        try {
            $value = json_decode($payload, true, AsyncTaskValue::MAX_DEPTH + 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new \InvalidArgumentException('Plugin task result is malformed.', previous: $failure);
        }
        if (!is_array($value) || ($value['version'] ?? null) !== self::VERSION
            || !is_string($value['owner'] ?? null) || $value['owner'] === ''
            || !is_int($value['generation'] ?? null) || $value['generation'] < 1
            || !is_bool($value['success'] ?? null)) {
            throw new \InvalidArgumentException('Plugin task result fields are invalid.');
        }
        if ($value['success']) {
            if (!array_key_exists('result', $value)) {
                throw new \InvalidArgumentException('Plugin task success result is missing.');
            }

            return [
                'owner' => $value['owner'],
                'generation' => $value['generation'],
                'result' => new AsyncTaskValue($value['result']),
            ];
        }
        if (!is_string($value['failure'] ?? null) || $value['failure'] === '' || strlen($value['failure']) > 256) {
            throw new \InvalidArgumentException('Plugin task failure result is invalid.');
        }

        return [
            'owner' => $value['owner'],
            'generation' => $value['generation'],
            'failure' => $value['failure'],
        ];
    }
}
