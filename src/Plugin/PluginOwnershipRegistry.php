<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Throwable;

final class PluginOwnershipRegistry
{
    /** @var array<string, array<string, callable(): void>> */
    private array $cleanup = [];

    public function own(string $plugin, string $resourceId, callable $cleanup): void
    {
        $key = strtolower($plugin);
        if (isset($this->cleanup[$key][$resourceId])) {
            throw new PluginException("Plugin resource already exists: {$resourceId}");
        }
        $this->cleanup[$key][$resourceId] = $cleanup;
    }

    /** @return list<Throwable> */
    public function releaseAll(string $plugin): array
    {
        $key = strtolower($plugin);
        $callbacks = array_reverse($this->cleanup[$key] ?? [], true);
        unset($this->cleanup[$key]);
        $failures = [];
        foreach ($callbacks as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable $throwable) {
                $failures[] = $throwable;
            }
        }

        return $failures;
    }

    public function count(string $plugin): int
    {
        return count($this->cleanup[strtolower($plugin)] ?? []);
    }

    public function forget(string $plugin, string $resourceId): void
    {
        $key = strtolower($plugin);
        unset($this->cleanup[$key][$resourceId]);
        if (($this->cleanup[$key] ?? []) === []) {
            unset($this->cleanup[$key]);
        }
    }
}
