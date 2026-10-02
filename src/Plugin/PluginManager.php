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

use Bedriox\Api\Plugin\Plugin;
use Closure;
use Throwable;

final class PluginManager implements PluginRuntimeControl
{
    /** @var array<string, PluginRecord> */
    private array $records = [];
    /** @var list<PluginFailure> */
    private array $failures = [];

    public function __construct(
        private readonly PluginExecutionContext $executionContext,
        private readonly PluginOwnershipRegistry $ownership,
        private readonly ?Closure $failureObserver = null,
    ) {}

    public function add(PluginManifest $manifest, Plugin $plugin): void
    {
        $key = strtolower($manifest->name);
        if (isset($this->records[$key])) {
            throw new PluginException("Duplicate plugin: {$manifest->name}");
        }
        if (!$plugin instanceof $manifest->main) {
            throw new PluginException("Plugin {$manifest->name} does not match its declared entry point.");
        }
        $this->records[$key] = new PluginRecord($manifest, $plugin);
    }

    public function loadAll(): void
    {
        foreach ($this->orderedRecords() as $record) {
            $this->load($record->manifest->name);
        }
    }

    public function enableAll(): void
    {
        foreach ($this->orderedRecords() as $record) {
            $this->enable($record->manifest->name);
        }
    }

    public function load(string $plugin): bool
    {
        $record = $this->record($plugin);
        if ($record->state !== PluginLifecycleState::VALIDATED) {
            return $record->state === PluginLifecycleState::LOADED || $record->state === PluginLifecycleState::ENABLED;
        }
        if (!$this->dependenciesAvailable($record)) {
            $record->state = PluginLifecycleState::FAILED;

            return false;
        }
        if (!$this->invokeLifecycle($record, 'load', $record->plugin->onLoad(...))) {
            return false;
        }
        $record->state = PluginLifecycleState::LOADED;

        return true;
    }

    public function enable(string $plugin): bool
    {
        $record = $this->record($plugin);
        if ($record->state === PluginLifecycleState::ENABLED) {
            return true;
        }
        if ($record->state !== PluginLifecycleState::LOADED || !$this->dependenciesEnabled($record)) {
            if ($record->state === PluginLifecycleState::LOADED) {
                $record->state = PluginLifecycleState::FAILED;
            }

            return false;
        }
        $record->state = PluginLifecycleState::ENABLED;
        if (!$this->invokeLifecycle($record, 'enable', $record->plugin->onEnable(...))) {
            return false;
        }

        return true;
    }

    public function has(string $plugin): bool
    {
        return isset($this->records[strtolower($plugin)]);
    }

    public function disable(string $plugin): void
    {
        $record = $this->records[strtolower($plugin)] ?? null;
        if ($record === null || in_array($record->state, [PluginLifecycleState::DISABLED, PluginLifecycleState::DISABLING], true)) {
            return;
        }
        foreach (array_reverse($this->orderedRecords()) as $dependent) {
            if ($dependent->state === PluginLifecycleState::ENABLED
                && $this->containsName($dependent->manifest->dependencies, $record->manifest->name)) {
                $this->disable($dependent->manifest->name);
            }
        }
        $record->state = PluginLifecycleState::DISABLING;
        try {
            $this->invokeLifecycle($record, 'disable', $record->plugin->onDisable(...));
        } finally {
            $this->ownership->releaseAll($record->manifest->name);
            $record->state = PluginLifecycleState::DISABLED;
        }
    }

    public function disableAll(): void
    {
        foreach (array_reverse($this->orderedRecords()) as $record) {
            $this->disable($record->manifest->name);
        }
    }

    public function isEnabled(string $plugin): bool
    {
        return ($this->records[strtolower($plugin)]->state ?? null) === PluginLifecycleState::ENABLED;
    }

    public function version(string $plugin): string
    {
        return $this->record($plugin)->manifest->version;
    }

    public function state(string $plugin): PluginLifecycleState
    {
        return $this->record($plugin)->state;
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        $operation = $frame === null ? 'unknown' : $frame->operation;
        $this->failures[] = new PluginFailure($plugin, $operation, $failure, $frame);
        $this->notifyFailure($this->failures[array_key_last($this->failures)]);
        $record = $this->records[strtolower($plugin)] ?? null;
        if ($record === null) {
            return;
        }
        $record->state = PluginLifecycleState::FAILED;
        $this->ownership->releaseAll($record->manifest->name);
        foreach ($this->records as $dependent) {
            if ($dependent->state === PluginLifecycleState::ENABLED
                && $this->containsName($dependent->manifest->dependencies, $record->manifest->name)) {
                $this->disable($dependent->manifest->name);
            }
        }
    }

    /** @return list<PluginFailure> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return array<string, bool> Plugin display name to enabled state. */
    public function pluginStates(): array
    {
        $states = [];
        foreach ($this->records as $record) {
            $states[$record->manifest->name] = $record->state === PluginLifecycleState::ENABLED;
        }

        return $states;
    }

    private function invokeLifecycle(PluginRecord $record, string $operation, callable $callback): bool
    {
        $frame = new PluginExecutionFrame(
            $record->manifest->name,
            $record->manifest->version,
            $operation,
            startedAtNanoseconds: hrtime(true),
        );
        $this->executionContext->enter($frame);
        try {
            $callback();

            return true;
        } catch (Throwable $throwable) {
            $record->state = PluginLifecycleState::FAILED;
            $this->failures[] = new PluginFailure($record->manifest->name, $operation, $throwable, $frame);
            $this->notifyFailure($this->failures[array_key_last($this->failures)]);
            $this->ownership->releaseAll($record->manifest->name);

            return false;
        } finally {
            $this->executionContext->leave();
        }
    }

    /** @return list<PluginRecord> */
    private function orderedRecords(): array
    {
        $manifests = array_map(static fn(PluginRecord $record): PluginManifest => $record->manifest, array_values($this->records));
        $ordered = (new PluginDependencyResolver())->order($manifests);

        return array_map(fn(PluginManifest $manifest): PluginRecord => $this->record($manifest->name), $ordered);
    }

    private function dependenciesAvailable(PluginRecord $record): bool
    {
        foreach ($record->manifest->dependencies as $dependency) {
            $dependencyRecord = $this->records[strtolower($dependency)] ?? null;
            if ($dependencyRecord === null || $dependencyRecord->state === PluginLifecycleState::FAILED) {
                return false;
            }
        }

        return true;
    }

    private function dependenciesEnabled(PluginRecord $record): bool
    {
        foreach ($record->manifest->dependencies as $dependency) {
            if (!$this->isEnabled($dependency)) {
                return false;
            }
        }

        return true;
    }

    private function record(string $plugin): PluginRecord
    {
        return $this->records[strtolower($plugin)] ?? throw new PluginException("Unknown plugin: {$plugin}");
    }

    /** @param list<string> $names */
    private function containsName(array $names, string $name): bool
    {
        foreach ($names as $candidate) {
            if (strcasecmp($candidate, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function notifyFailure(PluginFailure $failure): void
    {
        if ($this->failureObserver === null) {
            return;
        }
        try {
            ($this->failureObserver)($failure);
        } catch (Throwable) {
            // Failure reporting cannot change plugin lifecycle behavior.
        }
    }
}
