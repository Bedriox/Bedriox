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

final class PluginDependencyResolver
{
    /**
     * @param list<PluginManifest> $manifests
     * @return list<PluginManifest>
     */
    public function order(array $manifests): array
    {
        $byName = [];
        foreach ($manifests as $manifest) {
            $key = strtolower($manifest->name);
            if (isset($byName[$key])) {
                throw new PluginException("Duplicate plugin: {$manifest->name}");
            }
            $byName[$key] = $manifest;
        }
        ksort($byName, SORT_STRING);
        foreach ($byName as $manifest) {
            foreach ($manifest->dependencies as $dependency) {
                if (!isset($byName[strtolower($dependency)])) {
                    throw new PluginException("Missing dependency {$dependency} required by {$manifest->name}");
                }
            }
        }

        $state = [];
        $ordered = [];
        $visit = function (string $key, array $path) use (&$visit, &$state, &$ordered, $byName): void {
            if (($state[$key] ?? 0) === 2) {
                return;
            }
            if (($state[$key] ?? 0) === 1) {
                /** @var list<string> $cycle */
                $cycle = [...$path, $byName[$key]->name];
                throw new PluginException('Plugin dependency cycle: ' . implode(' -> ', $cycle));
            }
            $state[$key] = 1;
            $manifest = $byName[$key];
            $dependencies = [];
            foreach ([...$manifest->dependencies, ...$manifest->softDependencies] as $dependency) {
                $dependencyKey = strtolower($dependency);
                if (isset($byName[$dependencyKey])) {
                    $dependencies[$dependencyKey] = true;
                }
            }
            ksort($dependencies, SORT_STRING);
            foreach (array_keys($dependencies) as $dependencyKey) {
                $visit($dependencyKey, [...$path, $manifest->name]);
            }
            $state[$key] = 2;
            $ordered[] = $manifest;
        };

        foreach (array_keys($byName) as $key) {
            $visit($key, []);
        }

        return $ordered;
    }
}
