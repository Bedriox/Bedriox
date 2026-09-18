<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Throwable;

interface PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool;

    public function version(string $plugin): string;

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void;
}
