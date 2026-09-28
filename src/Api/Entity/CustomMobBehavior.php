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

namespace Bedriox\Api\Entity;

/**
 * Plugin-owned behavior attached to one server-owned custom mob.
 * General mutations use the ordinary bounded server API captured by the plugin;
 * per-tick mob control uses CustomMobTickContext::$controller.
 */
abstract class CustomMobBehavior
{
    public function onSpawn(CustomMobSpawnContext $context): void {}

    public function onTick(CustomMobTickContext $context): void {}

    public function onAiTick(CustomMobTickContext $context): void {}

    public function onDespawn(CustomMobDespawnContext $context): void {}
}
