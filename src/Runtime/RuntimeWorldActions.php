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

use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldActions;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/** @internal Resolves a canonical world handle into its current authoritative simulation. */
final class RuntimeWorldActions
{
    private ?WorldRuntimeManager $worlds = null;

    public function __construct(
        private readonly BlockCatalog $blocks,
        private readonly BlockStateRegistry $states,
        private readonly ?PluginActionBuffer $pluginActions = null,
    ) {}

    public function attach(WorldRuntimeManager $worlds): void
    {
        $this->worlds = $worlds;
    }

    public function actions(): WorldActions
    {
        return new WorldActions(
            fn(World $world, ApiBlockPosition $position): Block => $this->getBlock($world, $position),
            function (World $world, ApiBlockPosition $position, string $identifier): void {
                $this->setBlock($world, $position, $identifier);
            },
        );
    }

    private function getBlock(World $world, ApiBlockPosition $position): Block
    {
        $runtime = $this->runtime($world);
        $state = $runtime->opened->world->blockStateAt($position->x, $position->y, $position->z);
        $identifier = $this->blocks->typeForInternalId($state, $this->states)->identifier();

        return new Block($position, $identifier);
    }

    private function setBlock(World $world, ApiBlockPosition $position, string $identifier): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Block identifier must be canonical and namespaced.');
        }
        $runtime = $this->runtime($world);
        $action = static function () use ($runtime, $position, $identifier): void {
            if (!$runtime->simulation->enqueuePluginBlock(
                'api',
                new BlockPosition($position->x, $position->y, $position->z),
                $identifier,
            )) {
                throw new OverflowException('The authoritative world action queue rejected the block change.');
            }
        };
        if ($this->pluginActions?->isCapturing() === true) {
            $this->pluginActions->stage($action);
        } else {
            $action();
        }
    }

    private function runtime(World $world): ManagedWorldRuntime
    {
        return ($this->worlds ?? throw new LogicException('World actions are not attached to the runtime.'))
            ->assertCurrent($world);
    }
}
