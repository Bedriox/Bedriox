<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Task;

final readonly class SpawnWorldStorageOwnerRequest
{
    public function __construct(
        public string $epochHex,
        public string $applicationVersion,
        public string $endpoint,
        public string $tokenHex,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $epochHex) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $tokenHex) !== 1
            || $applicationVersion === '' || strlen($applicationVersion) > 128
            || strlen($endpoint) > 256 || preg_match('/^tcp:\/\/127\.0\.0\.1:\d{1,5}$/D', $endpoint) !== 1) {
            throw new \InvalidArgumentException('World storage owner launch request is invalid.');
        }
    }

    public function encode(): string
    {
        return json_encode([
            'epoch' => $this->epochHex,
            'applicationVersion' => $this->applicationVersion,
            'endpoint' => $this->endpoint,
            'token' => $this->tokenHex,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $payload): self
    {
        $value = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value) || count($value) !== 4
            || !is_string($value['epoch'] ?? null)
            || !is_string($value['applicationVersion'] ?? null)
            || !is_string($value['endpoint'] ?? null)
            || !is_string($value['token'] ?? null)) {
            throw new \InvalidArgumentException('World storage owner launch payload is invalid.');
        }

        return new self($value['epoch'], $value['applicationVersion'], $value['endpoint'], $value['token']);
    }
}
