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

namespace Bedriox\Server\Plugin\Entity;

use Bedriox\Api\Entity\Human;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use WeakReference;

/** Tracks plugin-owned humans without retaining handles after their callers release them. */
final class HumanOwnershipRegistry
{
    private const string RESOURCE_ID = 'human-entities';

    /** @var array<string, array<string, WeakReference<Human>>> */
    private array $humans = [];

    /** @var array<string, true> */
    private array $registered = [];

    public function __construct(private readonly PluginOwnershipRegistry $ownership) {}

    public function track(string $plugin, Human $human): void
    {
        $owner = strtolower($plugin);
        $this->prune($owner);
        $identity = strtolower($human->getUniqueId());
        if (isset($this->humans[$owner][$identity])) {
            throw new PluginException("Plugin human already exists: {$identity}");
        }
        if (!isset($this->registered[$owner])) {
            $this->ownership->own(
                $plugin,
                self::RESOURCE_ID,
                fn(): null => $this->release($owner),
            );
            $this->registered[$owner] = true;
        }
        $this->humans[$owner][$identity] = WeakReference::create($human);
    }

    public function count(string $plugin): int
    {
        $owner = strtolower($plugin);
        $this->prune($owner);

        return count($this->humans[$owner] ?? []);
    }

    private function release(string $owner): null
    {
        $references = $this->humans[$owner] ?? [];
        unset($this->humans[$owner], $this->registered[$owner]);
        foreach ($references as $reference) {
            $human = $reference->get();
            if ($human !== null && $human->getController()->isAvailable()) {
                $human->getController()->despawn();
            }
        }

        return null;
    }

    private function prune(string $owner): void
    {
        foreach ($this->humans[$owner] ?? [] as $identity => $reference) {
            $human = $reference->get();
            if ($human === null || !$human->getController()->isAvailable()) {
                unset($this->humans[$owner][$identity]);
            }
        }
        if (($this->humans[$owner] ?? []) === []) {
            unset($this->humans[$owner]);
        }
    }
}
