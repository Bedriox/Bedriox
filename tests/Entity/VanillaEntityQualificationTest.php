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

use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class VanillaEntityQualificationTest extends TestCase
{
    public function testEveryRegisteredVanillaFactoryPreservesItsDefinitionAndCollisionContract(): void
    {
        $registrations = EntityDefinitionRegistry::baseline()->all();
        self::assertNotEmpty($registrations);

        foreach ($registrations as $index => $registration) {
            $entity = ($registration->factory)(
                EntityUuid::random(),
                $index + 1,
                'qualification',
                new Position(0.5, 64.0, 0.5),
                35.0,
                -10.0,
            );
            self::assertInstanceOf(AbstractEntity::class, $entity, $registration->definition->networkIdentifier);
            self::assertSame($registration->definition->type, $entity->getType());
            self::assertSame($registration->definition->category, $entity->getCategory());
            self::assertSame($registration->definition->networkIdentifier, $entity->definition()->networkIdentifier);
            self::assertGreaterThan(0.0, $entity->getCollisionWidth());
            self::assertGreaterThan(0.0, $entity->getCollisionHeight());
            self::assertEquals($entity->collisionWidth(), $entity->getCollisionWidth());
            self::assertEquals($entity->collisionHeight(), $entity->getCollisionHeight());
            if (in_array($registration->definition->networkIdentifier, [
                'minecraft:ender_crystal',
                'minecraft:leash_knot',
            ], true)) {
                self::assertSame(0.0, $entity->getYaw());
                self::assertSame(0.0, $entity->getPitch());
            } else {
                self::assertSame(35.0, $entity->getYaw());
                self::assertSame(-10.0, $entity->getPitch());
            }
        }
    }
}
