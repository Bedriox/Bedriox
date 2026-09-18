<?php

declare(strict_types=1);

namespace Bedriox\Api\Plugin;

interface SourcePluginRegistrar
{
    /** Canonical root that the development provider may inspect. */
    public function pluginsDirectory(): string;

    /**
     * Stages one complete discovery result for admission after PHAR plugins
     * finish enabling.
     *
     * @param list<SourcePluginDefinition> $definitions
     */
    public function register(array $definitions): void;
}
