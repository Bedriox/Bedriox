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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Server\Entity\Mount\MountEntityDefinitions;
use Bedriox\Server\Entity\RegisteredEntityDefinition;
use Bedriox\Server\Simulation\Position;

/** @internal Focused factory list for the expanded land-animal milestone. */
final class ExpandedLandEntityRegistrations
{
    /** @return list<RegisteredEntityDefinition> */
    public static function all(): array
    {
        return [
            self::registration(TameableEntityDefinitions::wolf(), WolfEntity::class),
            self::registration(TameableEntityDefinitions::cat(), CatEntity::class),
            self::registration(LandAnimalEntityDefinitions::ocelot(), OcelotEntity::class),
            self::registration(LandAnimalEntityDefinitions::fox(), FoxEntity::class),
            self::registration(LandAnimalEntityDefinitions::goat(), GoatEntity::class),
            self::registration(LandAnimalEntityDefinitions::panda(), PandaEntity::class),
            self::registration(LandAnimalEntityDefinitions::polarBear(), PolarBearEntity::class),
            self::registration(LandAnimalEntityDefinitions::armadillo(), ArmadilloEntity::class),
            self::registration(LandAnimalEntityDefinitions::mooshroom(), MooshroomEntity::class),
            self::registration(LandAnimalEntityDefinitions::sniffer(), SnifferEntity::class),
            self::registration(MountEntityDefinitions::horse(), HorseEntity::class),
            self::registration(MountEntityDefinitions::donkey(), DonkeyEntity::class),
            self::registration(MountEntityDefinitions::mule(), MuleEntity::class),
            self::registration(MountEntityDefinitions::camel(), CamelEntity::class),
            self::registration(MountEntityDefinitions::llama(), LlamaEntity::class),
            self::registration(MountEntityDefinitions::traderLlama(), TraderLlamaEntity::class),
            self::registration(MountEntityDefinitions::skeletonHorse(), SkeletonHorseEntity::class),
            self::registration(MountEntityDefinitions::zombieHorse(), ZombieHorseEntity::class),
        ];
    }

    /**
     * @param class-string<\Bedriox\Server\Entity\AbstractEntity> $class
     */
    private static function registration(
        \Bedriox\Server\Entity\EntityDefinition $definition,
        string $class,
    ): RegisteredEntityDefinition {
        return new RegisteredEntityDefinition(
            $definition,
            static fn(
                string $uuid,
                int $runtimeId,
                string $world,
                Position $position,
                float $yaw,
                float $pitch,
            ): \Bedriox\Server\Entity\AbstractEntity => new $class(
                $uuid,
                $runtimeId,
                $world,
                $position,
                yaw: $yaw,
                pitch: $pitch,
            ),
        );
    }
}
