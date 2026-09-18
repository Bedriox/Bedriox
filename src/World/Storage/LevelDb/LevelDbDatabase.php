<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

/** Minimal binary-safe LevelDB boundary used by the world provider. */
interface LevelDbDatabase
{
    public function get(string $key): ?string;

    /**
     * @param array<string, string> $puts
     * @param list<string>          $deletes
     */
    public function writeBatch(array $puts, array $deletes): void;

    /** Repeated calls must be harmless. */
    public function close(): void;
}
