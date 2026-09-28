<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\World\Generator\GeneratorDefinition as ApiGeneratorDefinition;
use Bedriox\Api\World\Generator\GeneratorRegistrar;
use Bedriox\Server\World\Generator\GeneratorDefinition;
use Bedriox\Server\World\Generator\GeneratorExecution;
use Bedriox\Server\World\Generator\GeneratorIdentifier;
use Bedriox\Server\World\Generator\GeneratorRegistry;
use Bedriox\Server\World\Generator\PluginWorldGeneratorAdapter;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;
use RuntimeException;
use Throwable;

final class OwnedGeneratorRegistrar implements GeneratorRegistrar
{
    public const int MAXIMUM_DEFINITIONS_PER_PLUGIN = 16;

    /** @var array<string, true> */
    private array $owned = [];

    public function __construct(
        private readonly string $plugin,
        private readonly GeneratorRegistry $registry,
        private readonly PluginOwnershipRegistry $ownership,
    ) {}

    public function register(ApiGeneratorDefinition $definition, bool $replace = false): void
    {
        $identifier = new GeneratorIdentifier($definition->identifier);
        $existing = $this->registry->get($identifier);
        if ($existing !== null && strcasecmp($existing->owner, $this->plugin) !== 0) {
            throw new PluginException("World generator \"{$identifier->value}\" belongs to another owner.");
        }
        if ($existing === null && count($this->owned) >= self::MAXIMUM_DEFINITIONS_PER_PLUGIN) {
            throw new PluginException("Plugin {$this->plugin} reached its world-generator definition limit.");
        }

        $class = $definition->generatorClass;
        $id = $identifier->value;
        $version = $definition->version;
        $workerSource = WorkerGeneratorSource::capture($class);
        $internal = new GeneratorDefinition(
            $identifier,
            $version,
            $this->plugin,
            static fn(\Bedriox\Server\World\Generator\GeneratorContext $context): PluginWorldGeneratorAdapter => new PluginWorldGeneratorAdapter(
                $id,
                $version,
                $class,
                $context,
            ),
            GeneratorExecution::WORKER,
            $workerSource,
        );
        $resource = 'world-generator:' . $id;
        $addedOwnership = !isset($this->owned[$id]);
        if ($addedOwnership) {
            $this->ownership->own($this->plugin, $resource, function () use ($identifier, $id): void {
                $this->registry->unregister($identifier, $this->plugin);
                unset($this->owned[$id]);
            });
        }
        try {
            $this->registry->register($internal, $replace);
            $this->owned[$id] = true;
        } catch (Throwable $failure) {
            if ($addedOwnership) {
                $this->ownership->forget($this->plugin, $resource);
            }
            if ($failure instanceof RuntimeException) {
                throw new PluginException($failure->getMessage(), previous: $failure);
            }
            throw $failure;
        }
    }
}
