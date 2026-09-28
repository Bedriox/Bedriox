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

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Persistence\World\ProcessWorldProvider;
use Bedriox\Server\Worker\World\PendingWorldPreparation;
use Bedriox\Server\Worker\World\WorldPreparationResult;
use Closure;

/** @internal Pollable storage startup whose authoritative world composition remains in the parent process. */
final class PendingOpenedWorld
{
    private ?OpenedWorld $opened = null;
    private ?ProcessWorldProvider $provider;
    private ?WorldPreparationResult $preparation = null;

    /**
     * @param Closure(ProcessWorldProvider, ?WorldPreparationResult): OpenedWorld $compose
     * @param null|Closure(WorldPreparationResult): ProcessWorldProvider $beginProvider
     */
    public function __construct(
        ProcessWorldProvider|PendingWorldPreparation $pending,
        private readonly Closure $compose,
        private readonly ?Closure $beginProvider = null,
    ) {
        if ($pending instanceof PendingWorldPreparation && $beginProvider === null) {
            throw new \InvalidArgumentException('Deferred world opening requires a provider factory.');
        }
        if ($pending instanceof ProcessWorldProvider && $beginProvider !== null) {
            throw new \InvalidArgumentException('An opened provider cannot also have a deferred provider factory.');
        }
        $this->provider = $pending instanceof ProcessWorldProvider ? $pending : null;
        $this->pendingPreparation = $pending instanceof PendingWorldPreparation ? $pending : null;
    }

    private readonly ?PendingWorldPreparation $pendingPreparation;

    public function poll(): ?OpenedWorld
    {
        if ($this->opened instanceof OpenedWorld) {
            return $this->opened;
        }
        if (!$this->provider instanceof ProcessWorldProvider) {
            $preparation = $this->pendingPreparation?->poll();
            if (!$preparation instanceof WorldPreparationResult) {
                return null;
            }
            $this->preparation = $preparation;
            $beginProvider = $this->beginProvider;
            if ($beginProvider === null) {
                throw new \LogicException('Deferred provider factory is unavailable.');
            }
            $this->provider = $beginProvider($preparation);
        }
        if (!$this->provider->pollStartup()) {
            return null;
        }

        return $this->opened = ($this->compose)($this->provider, $this->preparation);
    }

    public function cancel(): void
    {
        if (!$this->opened instanceof OpenedWorld) {
            if ($this->provider instanceof ProcessWorldProvider) {
                $this->provider->close();
            } else {
                $this->pendingPreparation?->cancel();
            }
        }
    }
}
