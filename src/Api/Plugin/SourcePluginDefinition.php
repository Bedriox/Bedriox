<?php

declare(strict_types=1);

namespace Bedriox\Api\Plugin;

use Closure;

/**
 * A source plugin already discovered and validated by development tooling.
 *
 * Bedriox never scans the source tree. It validates this bounded description,
 * invokes the factory during lifecycle admission, and calls cleanup when the
 * admitted plugin is released.
 */
final readonly class SourcePluginDefinition
{
    /**
     * @param list<string> $authors
     * @param list<string> $dependencies
     * @param list<string> $softDependencies
     * @param Closure(PluginContext): Plugin $factory
     * @param Closure(): void $cleanup
     */
    public function __construct(
        public int $schema,
        public string $name,
        public string $version,
        public string $api,
        public string $main,
        public string $namespace,
        public array $authors,
        public array $dependencies,
        public array $softDependencies,
        public string $load,
        private Closure $factory,
        private Closure $cleanup,
    ) {}

    public function instantiate(PluginContext $context): Plugin
    {
        return ($this->factory)($context);
    }

    public function release(): void
    {
        ($this->cleanup)();
    }
}
