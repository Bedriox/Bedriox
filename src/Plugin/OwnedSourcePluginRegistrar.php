<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Plugin\SourcePluginDefinition;
use Bedriox\Api\Plugin\SourcePluginRegistrar;
use Closure;

final readonly class OwnedSourcePluginRegistrar implements SourcePluginRegistrar
{
    /** @param Closure(string, list<SourcePluginDefinition>): void $register */
    public function __construct(
        private string $plugin,
        private string $pluginsDirectory,
        private Closure $register,
    ) {}

    public function pluginsDirectory(): string
    {
        return $this->pluginsDirectory;
    }

    public function register(array $definitions): void
    {
        ($this->register)($this->plugin, $definitions);
    }
}
