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

namespace Bedriox\Server\Entity\Vanilla\Nether;

use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\RegisteredEntityDefinition;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;

/** @internal Focused factory list for Nether-native entity implementations. */
final class NetherEntityRegistrations
{
    /** @return list<RegisteredEntityDefinition> */
    public static function all(): array
    {
        return [
            self::registration(VanillaEntityDefinitions::blaze(), BlazeEntity::class),
            self::registration(VanillaEntityDefinitions::ghast(), GhastEntity::class),
            self::registration(VanillaEntityDefinitions::happyGhast(), HappyGhastEntity::class),
            self::registration(VanillaEntityDefinitions::hoglin(), HoglinEntity::class),
            self::registration(VanillaEntityDefinitions::piglin(), PiglinEntity::class),
            self::registration(VanillaEntityDefinitions::piglinBrute(), PiglinBruteEntity::class),
            self::registration(VanillaEntityDefinitions::strider(), StriderEntity::class),
            self::registration(VanillaEntityDefinitions::zoglin(), ZoglinEntity::class),
            self::registration(VanillaEntityDefinitions::zombifiedPiglin(), ZombifiedPiglinEntity::class),
        ];
    }

    /** @param class-string<AbstractEntity> $class */
    private static function registration(EntityDefinition $definition, string $class): RegisteredEntityDefinition
    {
        return new RegisteredEntityDefinition(
            $definition,
            static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): AbstractEntity => new $class($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch),
        );
    }
}
