<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Plugin\Plugin;

final class PluginRecord
{
    public function __construct(
        public readonly PluginManifest $manifest,
        public readonly Plugin $plugin,
        public PluginLifecycleState $state = PluginLifecycleState::VALIDATED,
    ) {}
}
