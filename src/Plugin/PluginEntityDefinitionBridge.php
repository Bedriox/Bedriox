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

use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\PluginMobEntity;
use Bedriox\Server\Entity\RegisteredEntityDefinition;
use Bedriox\Server\Simulation\Position;
use UnexpectedValueException;

/** @internal Projects validated plugin definitions into the authoritative entity runtime registry. */
final readonly class PluginEntityDefinitionBridge
{
    public function __construct(
        private EntityDefinitionRegistry $definitions,
        private PluginEntityRegistrar $plugins,
    ) {}

    public function register(RegisteredCustomMobDefinition $registration, bool $replace): void
    {
        $owner = $registration->owner;
        $custom = $registration->definition;
        $appearance = $this->definitions->get($custom->networkAppearance);
        if ($appearance === null || $appearance->owner !== null) {
            throw new UnexpectedValueException('Custom mob network appearance is not admitted by the active vanilla catalog.');
        }
        $definition = new EntityDefinition(
            $custom->type,
            $custom->category,
            $custom->networkAppearance->identifier(),
            $custom->width,
            $custom->height,
            $custom->maximumHealth,
            persistent: $custom->persistent,
        );
        $this->definitions->register(new RegisteredEntityDefinition(
            $definition,
            function (
                string $uuid,
                int $runtimeId,
                string $worldName,
                Position $position,
                float $yaw,
                float $pitch,
            ) use ($registration, $definition): PluginMobEntity {
                $behavior = $this->plugins->createBehavior($registration);
                if ($behavior === null) {
                    throw new UnexpectedValueException('Plugin mob behavior could not be created.');
                }

                return new PluginMobEntity(
                    $uuid,
                    $runtimeId,
                    $definition,
                    $worldName,
                    $position,
                    $registration,
                    $behavior,
                    yaw: $yaw,
                    pitch: $pitch,
                );
            },
            $owner,
        ), $replace);
    }

    public function unregister(string $identifier, string $owner): bool
    {
        return $this->definitions->unregisterOwned($identifier, $owner);
    }
}
