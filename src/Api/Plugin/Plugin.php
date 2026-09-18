<?php

declare(strict_types=1);

namespace Bedriox\Api\Plugin;

abstract class Plugin
{
    final public function __construct(private readonly PluginContext $context) {}

    final public function context(): PluginContext
    {
        return $this->context;
    }

    final public function logger(): PluginLogger
    {
        return $this->context->logger();
    }

    public function onLoad(): void {}

    public function onEnable(): void {}

    public function onDisable(): void {}
}
