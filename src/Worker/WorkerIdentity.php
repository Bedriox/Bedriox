<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

final readonly class WorkerIdentity
{
    public function __construct(
        public string $applicationVersion,
        public string $registryDigest,
        public string $phpVersion = PHP_VERSION,
        public string $architecture = PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
        public string $capabilityDigest = '',
    ) {}

    public static function current(string $applicationVersion, WorkerTaskRegistry $registry): self
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);

        return new self(
            $applicationVersion,
            $registry->digest(),
            PHP_VERSION,
            PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
            hash('sha256', implode("\n", $extensions)),
        );
    }

    /** @return array<string, string> */
    public function metadata(string $nonce): array
    {
        return [
            'application' => $this->applicationVersion,
            'architecture' => $this->architecture,
            'capabilities' => $this->capabilityDigest,
            'nonce' => $nonce,
            'php' => $this->phpVersion,
            'registry' => $this->registryDigest,
        ];
    }
}
