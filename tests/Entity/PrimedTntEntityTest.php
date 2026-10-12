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

use Bedriox\Api\Entity\Vanilla\PrimedTnt;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\Misc\PrimedTntEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrimedTntEntityTest extends TestCase
{
    public function testBaselineRegistrationUsesCurrentNetworkIdentityAndVanillaPhysics(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::PRIMED_TNT);
        $entity = ($registration->factory)(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.5, 64.0, 0.5),
            0.0,
            0.0,
        );

        self::assertInstanceOf(PrimedTnt::class, $entity);
        self::assertSame('minecraft:tnt', $registration->definition->networkIdentifier);
        self::assertSame(0.98, $registration->definition->width);
        self::assertSame(0.98, $registration->definition->height);
        self::assertSame(0.04, $registration->definition->gravity);
        self::assertSame(0.02, $registration->definition->drag);
        self::assertSame(PrimedTntEntity::DEFAULT_FUSE_TICKS, $entity->getFuseTicks());
    }

    public function testFuseAdvancesToDetonationWithoutUnderflow(): void
    {
        $entity = self::entity(3);

        self::assertFalse($entity->advanceFuse(2));
        self::assertSame(1, $entity->getFuseTicks());
        self::assertTrue($entity->advanceFuse());
        self::assertSame(0, $entity->getFuseTicks());
        self::assertTrue($entity->advanceFuse());
        self::assertSame(0, $entity->getFuseTicks());
    }

    public function testFusePublishesBoundedMetadataUpdatesForTheClientFlashAnimation(): void
    {
        $entity = self::entity(12);
        $initialPresentationRevision = $entity->presentationRevision();

        self::assertFalse($entity->advanceFuse());
        self::assertSame($initialPresentationRevision, $entity->presentationRevision());

        for ($tick = 0; $tick < 4; ++$tick) {
            self::assertFalse($entity->advanceFuse());
        }
        self::assertSame($initialPresentationRevision + 1, $entity->presentationRevision());
        self::assertSame(7, $entity->getFuseTicks());

        self::assertFalse($entity->advanceFuse(2));
        self::assertSame($initialPresentationRevision + 2, $entity->presentationRevision());
        self::assertSame(5, $entity->getFuseTicks());

        self::assertFalse($entity->advanceFuse());
        self::assertSame($initialPresentationRevision + 3, $entity->presentationRevision());
    }

    public function testVanillaPrimingMotionUsesBoundedHorizontalImpulse(): void
    {
        $motion = PrimedTntEntity::primingMotion(M_PI / 2.0);

        self::assertEqualsWithDelta(-0.02, $motion->x, 0.000_000_1);
        self::assertSame(0.2, $motion->y);
        self::assertEqualsWithDelta(0.0, $motion->z, 0.000_000_1);
        self::assertEqualsWithDelta(0.02, hypot($motion->x, $motion->z), 0.000_000_1);
    }

    public function testFuseAndUnderwaterVariantRoundTrip(): void
    {
        $source = self::entity(37, true);
        $restored = self::entity(80);
        $restored->restorePersistenceState(
            $source->persistenceVariant(),
            $source->persistenceSchemaVersion(),
            $source->persistenceData(),
        );

        self::assertSame(37, $restored->getFuseTicks());
        self::assertTrue($restored->worksUnderwater());
    }

    #[DataProvider('invalidPersistentStates')]
    public function testRejectsMalformedPersistentState(int|string|null $variant, int $schema, string $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::entity()->restorePersistenceState($variant, $schema, $data);
    }

    /** @return iterable<string, array{int|string|null, int, string}> */
    public static function invalidPersistentStates(): iterable
    {
        yield 'variant' => [1, 1, '{"fuseTicks":80,"underwater":false}'];
        yield 'schema' => [null, 2, '{"fuseTicks":80,"underwater":false}'];
        yield 'negative fuse' => [null, 1, '{"fuseTicks":-1,"underwater":false}'];
        yield 'oversized fuse' => [null, 1, '{"fuseTicks":32768,"underwater":false}'];
        yield 'wrong variant type' => [null, 1, '{"fuseTicks":80,"underwater":0}'];
        yield 'unknown field' => [null, 1, '{"fuseTicks":80,"underwater":false,"extra":true}'];
    }

    private static function entity(int $fuseTicks = 80, bool $underwater = false): PrimedTntEntity
    {
        return new PrimedTntEntity(
            EntityUuid::random(),
            2,
            'world',
            new Position(0.5, 64.0, 0.5),
            $fuseTicks,
            $underwater,
        );
    }
}
