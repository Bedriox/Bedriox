<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

final readonly class PluginManifest
{
    /**
     * @param list<string> $authors
     * @param list<string> $dependencies
     * @param list<string> $softDependencies
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
        public string $loadPhase,
    ) {}
}
