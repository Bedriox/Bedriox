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

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class ExpandedLandEntityRegistrationTest extends TestCase
{
    public function testEveryExpandedLandTypeHasOneConstructibleRegistration(): void
    {
        $registrations = [];
        foreach (VanillaEntityDefinitions::registrations() as $registration) {
            $registrations[$registration->definition->networkIdentifier] = $registration;
        }

        foreach (self::types() as $type) {
            $registration = $registrations[$type->value] ?? null;
            self::assertNotNull($registration, "Missing {$type->value} registration.");
            $entity = ($registration->factory)(
                EntityUuid::random(),
                100 + count($registrations),
                'world',
                new Position(0.0, 64.0, 0.0),
                45.0,
                10.0,
            );
            self::assertInstanceOf(AbstractLivingEntity::class, $entity);
            self::assertSame($type, $entity->getType());
            self::assertSame(45.0, $entity->getYaw());
            self::assertSame(10.0, $entity->getPitch());
        }
    }

    /** @return list<VanillaEntityType> */
    private static function types(): array
    {
        return [
            VanillaEntityType::WOLF,
            VanillaEntityType::CAT,
            VanillaEntityType::OCELOT,
            VanillaEntityType::HORSE,
            VanillaEntityType::DONKEY,
            VanillaEntityType::MULE,
            VanillaEntityType::CAMEL,
            VanillaEntityType::LLAMA,
            VanillaEntityType::TRADER_LLAMA,
            VanillaEntityType::SKELETON_HORSE,
            VanillaEntityType::ZOMBIE_HORSE,
            VanillaEntityType::FOX,
            VanillaEntityType::GOAT,
            VanillaEntityType::PANDA,
            VanillaEntityType::POLAR_BEAR,
            VanillaEntityType::ARMADILLO,
            VanillaEntityType::MOOSHROOM,
            VanillaEntityType::SNIFFER,
        ];
    }
}
