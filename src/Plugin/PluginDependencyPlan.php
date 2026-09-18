<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

final readonly class PluginDependencyPlan
{
    /**
     * @param list<PluginPackage> $ordered
     * @param array<string, string> $rejectedByName
     */
    public function __construct(
        public array $ordered,
        public array $rejectedByName,
    ) {}
}
