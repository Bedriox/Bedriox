<?php

declare(strict_types=1);

namespace Bedriox\Api\Plugin;

use Bedriox\Api\Event\EventRegistrar;
use Bedriox\Api\Server;

final class PluginContext
{
    public function __construct(
        private readonly string $name,
        private readonly PluginLogger $logger,
        private readonly EventRegistrar $events,
        private readonly Server $server,
        private readonly string $dataFolder,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function logger(): PluginLogger
    {
        return $this->logger;
    }

    public function events(): EventRegistrar
    {
        return $this->events;
    }

    public function server(): Server
    {
        return $this->server;
    }

    public function dataFolder(): string
    {
        return $this->dataFolder;
    }

}
