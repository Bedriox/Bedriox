<?php

declare(strict_types=1);

namespace Bedriox\Api\Plugin;

interface PluginLogger
{
    public function debug(string $message): void;

    public function info(string $message): void;

    public function warning(string $message): void;

    public function error(string $message): void;
}
