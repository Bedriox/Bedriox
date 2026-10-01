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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Entity\Controller\AngerableController;
use Bedriox\Api\Entity\Controller\TameableAnimalController;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\Concern\AngerStateTrait;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\TameableAnimalEntity;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Plugin\BufferedAngerableController;
use Bedriox\Server\Plugin\BufferedTameableAnimalController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BufferedTameableAnimalControllerTest extends TestCase
{
    public function testOwnershipAndSittingCommitAtomicallyWithPluginActions(): void
    {
        $actions = new PluginActionBuffer();
        $animal = self::animal();
        $controller = new BufferedTameableAnimalController($actions, $animal);
        $owner = self::player('00000000-0000-4000-8000-000000000002');

        self::assertInstanceOf(TameableAnimalController::class, $controller);
        $actions->begin();
        $controller->setOwner($owner);
        $controller->setSitting(true);
        self::assertFalse($animal->isTamed());
        $actions->discard();
        self::assertFalse($animal->isTamed());

        $actions->begin();
        $controller->setOwner($owner);
        $controller->setSitting(true);
        $actions->commit();

        self::assertSame($owner->uuid, $animal->getOwnerUniqueId());
        self::assertTrue($animal->isSitting());
    }

    public function testAngerCapturesValidatedEntityIdentityAndRespectsTransactions(): void
    {
        $actions = new PluginActionBuffer();
        $animal = self::animal();
        $target = new CowEntity(
            '00000000-0000-4000-8000-000000000003',
            3,
            'world',
            new Position(1.0, 64.0, 0.0),
        );
        $controller = new BufferedAngerableController($actions, $animal);

        self::assertInstanceOf(AngerableController::class, $controller);
        $actions->begin();
        $controller->setAngerTarget($target, 300);
        self::assertNull($animal->getAngerTargetUniqueId());
        $actions->commit();

        self::assertSame($target->getUniqueId(), $animal->getAngerTargetUniqueId());
        self::assertSame(300, $animal->getRemainingAngerTicks());

        $playerTarget = self::player('00000000-0000-4000-8000-000000000004');
        $controller->setAngerTarget($playerTarget, 20);
        self::assertSame($playerTarget->uuid, $animal->getAngerTargetUniqueId());
        self::assertSame(20, $animal->getRemainingAngerTicks());

        $controller->setAngerTarget(null, 0);
        self::assertNull($animal->getAngerTargetUniqueId());
        self::assertSame(0, $animal->getRemainingAngerTicks());
    }

    public function testControllerRejectsInvalidOwnerAndAngerBeforeStaging(): void
    {
        $actions = new PluginActionBuffer();
        $animal = self::animal();
        $tameable = new BufferedTameableAnimalController($actions, $animal);
        $angerable = new BufferedAngerableController($actions, $animal);
        $actions->begin();

        try {
            $tameable->setOwner(self::player('not-a-uuid'));
            self::fail('An invalid player identity must be rejected.');
        } catch (InvalidArgumentException) {
        }

        try {
            $angerable->setAngerTarget(null, 1);
            self::fail('Anger without a target must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $actions->commit();
        self::assertFalse($animal->isTamed());
        self::assertNull($animal->getAngerTargetUniqueId());
    }

    private static function animal(): TestTameableAnimalEntity
    {
        return new TestTameableAnimalEntity(
            '00000000-0000-4000-8000-000000000001',
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
    }

    private static function player(string $uuid): Player
    {
        return new Player(
            'Owner',
            $uuid,
            new ApiPosition(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}

final class TestTameableAnimalEntity extends TameableAnimalEntity implements MutableAngerState
{
    use AngerStateTrait;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::cow(),
            $worldName,
            $position,
            VanillaAiBehaviors::cow(),
        );
        $this->initializeBreedableState();
        $this->initializeTameableState();
        $this->initializeAngerState();
    }
}
