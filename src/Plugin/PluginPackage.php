<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Plugin\Plugin;
use Bedriox\Api\Plugin\PluginContext;
use Closure;

final readonly class PluginPackage
{
    /**
     * @param Closure(string): void $autoloader
     * @param Closure(PluginContext): Plugin $instantiate
     */
    public function __construct(
        public string $archive,
        public PluginManifest $manifest,
        public Closure $autoloader,
        public Closure $instantiate,
    ) {}
}
