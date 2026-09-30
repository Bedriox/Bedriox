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

namespace Bedriox\Server\Tests\Gameplay\Projectile;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Api\World\BlockFace;
use Bedriox\Server\Gameplay\Potion\AreaEffectCloudRegistry;
use Bedriox\Server\Gameplay\Projectile\ProjectileOwnerType;
use Bedriox\Server\Gameplay\Projectile\ProjectilePersistenceCodec;
use Bedriox\Server\Gameplay\Projectile\ProjectileRegistry;
use Bedriox\Server\Gameplay\Projectile\ProjectileState;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectilePersistenceCodec::class)]
final class ProjectilePersistenceCodecTest extends TestCase
{
    public function testActivePotionActorsSurviveARegistryRestartExactly(): void
    {
        $projectiles = new ProjectileRegistry(firstEntityId: 3_000);
        $arrow = $projectiles->spawnTippedArrow(
            'owner-uuid',
            42,
            PotionType::LONG_POISON,
            new Position(4.5, 72.25, -9.0),
            37.0,
            -18.0,
            2.25,
            \Bedriox\Server\Gameplay\Projectile\ArrowPickupMode::NONE,
        );
        $projectiles->tick(7);

        $clouds = new AreaEffectCloudRegistry(firstEntityId: 4_000);
        $cloud = $clouds->spawn('owner-uuid', PotionType::TURTLE_MASTER, new Position(-2.0, 65.0, 8.0));
        for ($tick = 0; $tick < 10; ++$tick) {
            $clouds->tick();
        }
        $clouds->affected($cloud->runtimeEntityId, 'victim-uuid');

        $codec = new ProjectilePersistenceCodec();
        $payload = $codec->encode('overworld', $projectiles->all(), $clouds->all());
        [$decodedProjectiles, $decodedClouds] = $codec->decode('overworld', $payload);

        self::assertCount(1, $decodedProjectiles);
        self::assertSame($arrow->runtimeEntityId, $decodedProjectiles[0]->runtimeEntityId);
        self::assertSame(7, $decodedProjectiles[0]->ageTicks);
        self::assertTrue($decodedProjectiles[0]->tippedArrow);
        self::assertFalse($decodedProjectiles[0]->pickupAllowed);
        self::assertEqualsWithDelta($projectiles->all()[0]->motion->y, $decodedProjectiles[0]->motion->y, 0.000001);

        self::assertCount(1, $decodedClouds);
        self::assertSame(10, $decodedClouds[0]->ageTicks);
        self::assertArrayHasKey('victim-uuid', $decodedClouds[0]->victimCooldowns);
        self::assertEqualsWithDelta($clouds->all()[0]->radius, $decodedClouds[0]->radius, 0.000001);

        $restoredProjectiles = new ProjectileRegistry(firstEntityId: 1);
        $restoredProjectiles->restore($decodedProjectiles[0]);
        $nextArrow = $restoredProjectiles->spawnTippedArrow(
            'next-owner',
            43,
            PotionType::WATER,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            1.0,
        );
        self::assertGreaterThan($decodedProjectiles[0]->runtimeEntityId, $nextArrow->runtimeEntityId);

        $restoredClouds = new AreaEffectCloudRegistry(firstEntityId: 1);
        $restoredClouds->restore($decodedClouds[0]);
        $nextCloud = $restoredClouds->spawn('next-owner', PotionType::WATER, new Position(0.0, 64.0, 0.0));
        self::assertGreaterThan($decodedClouds[0]->runtimeEntityId, $nextCloud->runtimeEntityId);
    }

    public function testEmbeddedArrowStateSurvivesPersistence(): void
    {
        $registry = new ProjectileRegistry(firstEntityId: 5_000);
        $arrow = $registry->spawnArrow(
            'owner',
            42,
            new Position(0.0, 64.0, 0.0),
            25.0,
            -8.0,
            2.0,
        )->embeddedAt(
            new Position(2.0, 65.0, 3.0),
            new BlockPosition(2, 65, 3),
            BlockFace::WEST,
        );
        $registry = new ProjectileRegistry(firstEntityId: 1);
        $registry->restore($arrow);

        $codec = new ProjectilePersistenceCodec();
        [$decoded] = $codec->decode('world', $codec->encode('world', $registry->all(), []));

        self::assertCount(1, $decoded);
        self::assertSame(ProjectileState::EMBEDDED, $decoded[0]->state);
        self::assertSame(BlockFace::WEST, $decoded[0]->embeddedFace);
        self::assertEquals($arrow->embeddedBlock, $decoded[0]->embeddedBlock);
        self::assertSame($arrow->yaw, $decoded[0]->yaw);
        self::assertSame($arrow->pitch, $decoded[0]->pitch);
    }

    public function testEntityOwnerTypeAndIdentitySurvivePersistence(): void
    {
        $registry = new ProjectileRegistry(firstEntityId: 6_000);
        $registry->spawnArrow(
            '5c8fd1fe-ec31-4bd4-ab14-2c68732d88ea',
            91,
            new Position(1.0, 65.0, 2.0),
            35.0,
            -10.0,
            2.0,
            ownerType: ProjectileOwnerType::ENTITY,
        );
        $codec = new ProjectilePersistenceCodec();
        [$decoded] = $codec->decode('world', $codec->encode('world', $registry->all(), []));

        self::assertCount(1, $decoded);
        self::assertSame(ProjectileOwnerType::ENTITY, $decoded[0]->ownerType);
        self::assertSame('5c8fd1fe-ec31-4bd4-ab14-2c68732d88ea', $decoded[0]->ownerUuid);
        self::assertSame(91, $decoded[0]->ownerRuntimeEntityId);
    }

    public function testRejectsMalformedOversizedAndCrossWorldSnapshots(): void
    {
        $codec = new ProjectilePersistenceCodec();

        foreach ([
            '',
            '{',
            json_encode(['version' => 1, 'world' => 'other', 'projectiles' => [], 'clouds' => []], JSON_THROW_ON_ERROR),
            str_repeat('x', ProjectilePersistenceCodec::MAXIMUM_BYTES + 1),
        ] as $payload) {
            try {
                $codec->decode('overworld', $payload);
                self::fail('Invalid potion entity state was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsUnknownPersistedProjectileOwnerType(): void
    {
        $registry = new ProjectileRegistry();
        $registry->spawnArrow('owner', 42, new Position(0.0, 64.0, 0.0), 0.0, 0.0, 1.0);
        $codec = new ProjectilePersistenceCodec();
        $document = json_decode($codec->encode('world', $registry->all(), []), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !isset($document['projectiles']) || !is_array($document['projectiles'])
            || !isset($document['projectiles'][0])
            || !is_array($document['projectiles'][0])) {
            self::fail('Encoded projectile persistence document has an invalid test shape.');
        }
        $document['projectiles'][0]['ownerType'] = 'unknown';

        $this->expectException(InvalidArgumentException::class);
        $codec->decode('world', json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function testThrownTridentPersistsButFishingHookDoesNot(): void
    {
        $projectiles = new ProjectileRegistry(firstEntityId: 5_000);
        $projectiles->spawnTrident(
            'owner',
            42,
            PotionType::WATER,
            new Position(1.0, 70.0, 1.0),
            0.0,
            0.0,
            2.4,
            7.5,
            2,
            true,
            true,
            new InventoryStack('minecraft:trident', 1, 17, damage: 4),
        );
        $projectiles->spawnFishingHook(
            'owner',
            44,
            new Position(1.0, 70.0, 1.0),
            0.0,
            0.0,
            1,
            2,
        );

        $codec = new ProjectilePersistenceCodec();
        [$decoded] = $codec->decode('overworld', $codec->encode('overworld', $projectiles->all(), []));

        self::assertCount(1, $decoded);
        self::assertSame(ProjectileType::TRIDENT, $decoded[0]->type);
        self::assertSame(2, $decoded[0]->loyaltyLevel);
        self::assertTrue($decoded[0]->channeling);
        $carriedItem = $decoded[0]->carriedItem;
        self::assertNotNull($carriedItem);
        self::assertSame('minecraft:trident', $carriedItem->identifier);
        self::assertSame(4, $carriedItem->damage);
    }
}
