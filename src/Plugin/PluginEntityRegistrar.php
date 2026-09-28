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

use Bedriox\Api\Entity\CustomEntityState;
use Bedriox\Api\Entity\CustomMobBehavior;
use Bedriox\Api\Entity\CustomMobDefinition;
use Bedriox\Api\Entity\CustomMobDespawnContext;
use Bedriox\Api\Entity\CustomMobSpawnContext;
use Bedriox\Api\Entity\CustomMobTickContext;
use Bedriox\Api\Entity\EntityType;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Owner-scoped registry and attributed invocation boundary for plugin-defined mobs.
 * Authoritative entity construction and world mutation remain outside this service.
 */
final class PluginEntityRegistrar
{
    private const string OWNERSHIP_RESOURCE = 'custom-entity-definitions';

    /** @var array<string, RegisteredCustomMobDefinition> */
    private array $definitions = [];

    /** @var array<string, true> lowercase plugin owners with registered cleanup */
    private array $owned = [];

    /** @var array<string, int> lowercase plugin owner => current activation epoch */
    private array $ownerEpochs = [];

    private int $nextOwnerEpoch = 1;

    private int $nextGeneration = 1;

    private ?PluginEntityDefinitionBridge $definitionBridge = null;

    public function __construct(
        private readonly PluginRuntimeControl $plugins,
        private readonly PluginExecutionContext $execution,
        private readonly PluginActionBuffer $actions,
        private readonly PluginOwnershipRegistry $ownership,
        private readonly int $maximumDefinitions = 512,
        private readonly int $maximumDefinitionsPerPlugin = 64,
    ) {
        if ($maximumDefinitions < 1 || $maximumDefinitions > 512
            || $maximumDefinitionsPerPlugin < 1 || $maximumDefinitionsPerPlugin > $maximumDefinitions) {
            throw new InvalidArgumentException('Plugin entity registration limits are invalid.');
        }
    }

    public function register(string $plugin, CustomMobDefinition $definition, bool $replace = false): void
    {
        self::validateOwner($plugin);
        if (!$this->plugins->isEnabled($plugin)) {
            throw new PluginException("Disabled plugin {$plugin} cannot register entity definitions.");
        }
        $identifier = $definition->type->identifier();
        $existing = $this->definitions[$identifier] ?? null;
        if ($existing !== null && strcasecmp($existing->owner, $plugin) !== 0) {
            throw new PluginException("Entity definition {$identifier} is owned by another plugin.");
        }
        if ($existing !== null && !$replace) {
            throw new PluginException("Entity definition {$identifier} is already registered by this plugin.");
        }
        if ($existing === null && count($this->definitions) >= $this->maximumDefinitions) {
            throw new PluginException('The custom entity-definition registration limit has been reached.');
        }
        if ($existing === null && $this->ownedCount($plugin) >= $this->maximumDefinitionsPerPlugin) {
            throw new PluginException("Plugin {$plugin} reached its custom entity-definition registration limit.");
        }

        $ownerKey = strtolower($plugin);
        $addedOwnership = !isset($this->owned[$ownerKey]);
        if ($addedOwnership) {
            if ($this->nextOwnerEpoch >= PHP_INT_MAX) {
                throw new PluginException('Plugin entity owner-epoch space is exhausted.');
            }
            $this->ownerEpochs[$ownerKey] = $this->nextOwnerEpoch++;
            $this->ownership->own($plugin, self::OWNERSHIP_RESOURCE, function () use ($plugin, $ownerKey): void {
                $this->removeOwnerDefinitions($plugin);
                unset($this->owned[$ownerKey], $this->ownerEpochs[$ownerKey]);
            });
            $this->owned[$ownerKey] = true;
        }
        try {
            if ($this->nextGeneration >= PHP_INT_MAX) {
                throw new PluginException('Plugin entity definition-generation space is exhausted.');
            }
            $registration = new RegisteredCustomMobDefinition(
                $plugin,
                $definition,
                $this->ownerEpochs[$ownerKey],
                $this->nextGeneration++,
            );
            $this->definitionBridge?->register($registration, $replace);
            $this->definitions[$identifier] = $registration;
        } catch (Throwable $failure) {
            if ($addedOwnership) {
                $this->ownership->forget($plugin, self::OWNERSHIP_RESOURCE);
                unset($this->owned[$ownerKey], $this->ownerEpochs[$ownerKey]);
            }
            throw $failure;
        }
    }

    /** @internal Bound once by the server composition root before plugins start. */
    public function bindDefinitionBridge(PluginEntityDefinitionBridge $bridge): void
    {
        if ($this->definitionBridge !== null && $this->definitionBridge !== $bridge) {
            throw new PluginException('Plugin entity-definition bridge is already bound.');
        }
        if ($this->definitionBridge === null && $this->definitions !== []) {
            throw new PluginException('Plugin entity-definition bridge must be bound before registration.');
        }
        $this->definitionBridge = $bridge;
    }

    public function has(EntityType|string $type): bool
    {
        return isset($this->definitions[self::identifier($type)]);
    }

    public function definition(EntityType|string $type): ?RegisteredCustomMobDefinition
    {
        return $this->definitions[self::identifier($type)] ?? null;
    }

    /** @return list<RegisteredCustomMobDefinition> */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }

    /** @internal Shared transaction buffer used by custom-mob control intents. */
    public function actions(): PluginActionBuffer
    {
        return $this->actions;
    }

    public function createBehavior(EntityType|string|RegisteredCustomMobDefinition $type): ?CustomMobBehavior
    {
        $registration = $this->resolve($type);
        if ($registration === null || !$this->isAvailable($registration)) {
            return null;
        }

        return $this->invoke(
            $registration,
            'entity-factory',
            static function () use ($registration): CustomMobBehavior {
                $behavior = ($registration->definition->factory)();
                if (!$behavior instanceof CustomMobBehavior) {
                    throw new UnexpectedValueException('Custom mob factory returned an invalid behavior.');
                }

                return $behavior;
            },
        );
    }

    public function encodeState(
        EntityType|string|RegisteredCustomMobDefinition $type,
        CustomMobBehavior $behavior,
    ): ?CustomEntityState {
        $registration = $this->resolve($type);
        if ($registration === null || !$registration->definition->persistent
            || !$this->isAvailable($registration)) {
            return null;
        }

        return $this->invoke(
            $registration,
            'entity-state-encode',
            static function () use ($registration, $behavior): CustomEntityState {
                $state = $registration->definition->stateCodec->encode($behavior);
                if ($state->size() > $registration->definition->maximumStateBytes) {
                    throw new UnexpectedValueException('Custom mob state exceeds its definition byte limit.');
                }

                return $state;
            },
        );
    }

    public function restoreState(
        EntityType|string|RegisteredCustomMobDefinition $type,
        CustomMobBehavior $behavior,
        CustomEntityState $state,
    ): bool {
        $registration = $this->resolve($type);
        if ($registration === null || !$registration->definition->persistent
            || !$this->isAvailable($registration)) {
            return false;
        }
        if ($state->size() > $registration->definition->maximumStateBytes) {
            throw new InvalidArgumentException('Persisted custom mob state exceeds its definition byte limit.');
        }

        return $this->invokeVoid(
            $registration,
            'entity-state-restore',
            static function () use ($registration, $behavior, $state): void {
                $registration->definition->stateCodec->restore($behavior, $state);
            },
        );
    }

    public function invokeSpawn(
        EntityType|string|RegisteredCustomMobDefinition $type,
        CustomMobBehavior $behavior,
        CustomMobSpawnContext $context,
    ): bool {
        return $this->invokeBehavior(
            $type,
            'entity-spawn-hook',
            static function () use ($behavior, $context): void {
                $behavior->onSpawn($context);
            },
        );
    }

    public function invokeTick(
        EntityType|string|RegisteredCustomMobDefinition $type,
        CustomMobBehavior $behavior,
        CustomMobTickContext $context,
    ): bool {
        return $this->invokeBehavior(
            $type,
            'entity-tick-hook',
            static function () use ($behavior, $context): void {
                $behavior->onTick($context);
            },
        );
    }

    public function invokeAiTick(
        EntityType|string|RegisteredCustomMobDefinition $type,
        CustomMobBehavior $behavior,
        CustomMobTickContext $context,
    ): bool {
        return $this->invokeBehavior(
            $type,
            'entity-ai-tick-hook',
            static function () use ($behavior, $context): void {
                $behavior->onAiTick($context);
            },
        );
    }

    public function invokeDespawn(
        EntityType|string|RegisteredCustomMobDefinition $type,
        CustomMobBehavior $behavior,
        CustomMobDespawnContext $context,
    ): bool {
        return $this->invokeBehavior(
            $type,
            'entity-despawn-hook',
            static function () use ($behavior, $context): void {
                $behavior->onDespawn($context);
            },
        );
    }

    /** @param callable(): void $callback */
    private function invokeBehavior(
        EntityType|string|RegisteredCustomMobDefinition $type,
        string $operation,
        callable $callback,
    ): bool {
        $registration = $this->resolve($type);
        if ($registration === null || !$this->isAvailable($registration)) {
            return false;
        }

        return $this->invokeVoid($registration, $operation, $callback);
    }

    /** @param callable(): void $callback */
    private function invokeVoid(
        RegisteredCustomMobDefinition $registration,
        string $operation,
        callable $callback,
    ): bool {
        $result = $this->invoke(
            $registration,
            $operation,
            static function () use ($callback): bool {
                $callback();

                return true;
            },
        );

        return $result === true;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T|null
     */
    private function invoke(
        RegisteredCustomMobDefinition $registration,
        string $operation,
        callable $callback,
    ): mixed {
        $frame = new PluginExecutionFrame(
            $registration->owner,
            $this->plugins->version($registration->owner),
            $operation,
            listener: $registration->definition->type->identifier(),
            startedAtNanoseconds: hrtime(true),
        );
        $this->execution->enter($frame);
        $this->actions->begin();
        try {
            $result = $callback();
            if (!$this->isAvailable($registration)) {
                $this->actions->discard();

                return null;
            }
            $this->actions->commit();

            return $result;
        } catch (Throwable $failure) {
            $this->actions->discard();
            $this->plugins->disableAfterFailure($registration->owner, $failure, $frame);

            return null;
        } finally {
            $this->execution->leave();
        }
    }

    private function ownedCount(string $plugin): int
    {
        $count = 0;
        foreach ($this->definitions as $definition) {
            if (strcasecmp($definition->owner, $plugin) === 0) {
                ++$count;
            }
        }

        return $count;
    }

    private function removeOwnerDefinitions(string $plugin): void
    {
        foreach ($this->definitions as $identifier => $definition) {
            if (strcasecmp($definition->owner, $plugin) === 0) {
                $this->definitionBridge?->unregister($identifier, $definition->owner);
                unset($this->definitions[$identifier]);
            }
        }
    }

    public function isAvailable(RegisteredCustomMobDefinition $registration): bool
    {
        $current = $this->definitions[$registration->definition->type->identifier()] ?? null;

        return $current !== null
            && strcasecmp($current->owner, $registration->owner) === 0
            && $current->ownerEpoch === $registration->ownerEpoch
            && $this->plugins->isEnabled($registration->owner);
    }

    private function resolve(
        EntityType|string|RegisteredCustomMobDefinition $type,
    ): ?RegisteredCustomMobDefinition {
        return $type instanceof RegisteredCustomMobDefinition ? $type : $this->definition($type);
    }

    private static function identifier(EntityType|string $type): string
    {
        return $type instanceof EntityType ? $type->identifier() : $type;
    }

    private static function validateOwner(string $plugin): void
    {
        if ($plugin === '' || strlen($plugin) > 128 || preg_match('//u', $plugin) !== 1) {
            throw new InvalidArgumentException('Plugin entity-definition owner must be valid UTF-8 and bounded.');
        }
    }
}
