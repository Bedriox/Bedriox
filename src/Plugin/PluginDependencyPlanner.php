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

final class PluginDependencyPlanner
{
    /**
     * @param list<PluginPackage> $packages
     * @param list<string> $externalDependencies
     */
    public function plan(array $packages, array $externalDependencies = []): PluginDependencyPlan
    {
        $available = [];
        foreach ($packages as $package) {
            $available[strtolower($package->manifest->name)] = $package;
        }
        ksort($available, SORT_STRING);
        $external = [];
        foreach ($externalDependencies as $dependency) {
            $external[strtolower($dependency)] = true;
        }
        $rejected = [];
        do {
            $changed = false;
            foreach ($available as $key => $package) {
                foreach ($package->manifest->dependencies as $dependency) {
                    $dependencyKey = strtolower($dependency);
                    if (!isset($available[$dependencyKey]) && !isset($external[$dependencyKey])) {
                        $rejected[$key] = isset($rejected[$dependencyKey])
                            ? "required dependency {$dependency} was rejected"
                            : "required dependency {$dependency} is missing";
                        unset($available[$key]);
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        $incoming = array_fill_keys(array_keys($available), 0);
        $outgoing = array_fill_keys(array_keys($available), []);
        foreach ($available as $key => $package) {
            $dependencies = [];
            foreach ([...$package->manifest->dependencies, ...$package->manifest->softDependencies] as $dependency) {
                $dependencyKey = strtolower($dependency);
                if ($dependencyKey !== $key && isset($available[$dependencyKey])) {
                    $dependencies[$dependencyKey] = true;
                }
            }
            foreach (array_keys($dependencies) as $dependencyKey) {
                ++$incoming[$key];
                $outgoing[$dependencyKey][] = $key;
            }
        }
        $ready = array_keys(array_filter($incoming, static fn(int $count): bool => $count === 0));
        sort($ready, SORT_STRING);
        $ordered = [];
        while ($ready !== []) {
            $key = array_shift($ready);
            $ordered[] = $available[$key];
            foreach ($outgoing[$key] as $dependent) {
                --$incoming[$dependent];
                if ($incoming[$dependent] === 0) {
                    $ready[] = $dependent;
                    sort($ready, SORT_STRING);
                }
            }
        }
        if (count($ordered) !== count($available)) {
            foreach ($incoming as $key => $count) {
                if ($count > 0) {
                    $rejected[$key] = 'dependency ordering contains a cycle';
                }
            }
        }

        return new PluginDependencyPlan($ordered, $rejected);
    }
}
