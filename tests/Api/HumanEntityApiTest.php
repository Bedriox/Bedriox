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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Entity\Skin;
use Bedriox\Api\Entity\SkinAnimation;
use Bedriox\Api\Entity\Value\EmoteId;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\Entity\Value\SkinAnimationExpression;
use Bedriox\Api\Entity\Value\SkinAnimationType;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Player\PlayerSkinChangedEvent;
use Bedriox\Api\Event\Player\PlayerSkinChangeEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\Human\HumanEntityFactory;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HumanEntityApiTest extends TestCase
{
    public function testFactoryBuildsAPlayerShapedSessionlessLivingEntity(): void
    {
        $human = (new HumanEntityFactory())->create(
            '11111111-1111-4111-8111-111111111111',
            50,
            'world',
            new Position(1.5, 64.0, 2.5),
            'Shopkeeper',
            self::skin('one'),
        );

        self::assertSame(VanillaEntityType::PLAYER, $human->getType());
        self::assertSame(0.6, $human->getCollisionWidth());
        self::assertSame(1.8, $human->getCollisionHeight());
        self::assertFalse($human->isPersistent());
        self::assertSame('Shopkeeper', $human->getName());
        self::assertSame('one', $human->getSkin()->id);
    }

    public function testControllerSharesMotionAnimationAndNavigationCapabilities(): void
    {
        $world = new World('world', 1);
        $human = (new HumanEntityFactory())->create(
            '22222222-2222-4222-8222-222222222222',
            51,
            'world',
            new Position(1.5, 64.0, 2.5),
            'Guide',
            self::skin('one'),
            world: $world,
        );
        $controller = $human->getController();

        $human->setMotion(new Motion(0.2, 0.4, -0.1));
        $human->swingArm();
        $human->playEmote(new EmoteId('00000000-0000-4000-8000-000000000001'));
        $human->navigateTo(new ApiPosition(20.0, 64.0, 20.0), 0.3);

        self::assertSame(0.2, $human->getMotion()->x);
        self::assertTrue($controller->isNavigating());
        self::assertEquals(
            new ApiPosition(20.0, 64.0, 20.0, 0.0, 0.0, $world, WorldDimension::OVERWORLD),
            $controller->getNavigationTarget(),
        );
        self::assertCount(2, $human->drainAnimationRequests());
        self::assertSame([], $human->drainAnimationRequests());

        $controller->cancelNavigation();
        self::assertFalse($controller->isNavigating());
    }

    public function testSkinChangesAreFocusedAndRevisioned(): void
    {
        $human = (new HumanEntityFactory())->create(
            '33333333-3333-4333-8333-333333333333',
            52,
            'world',
            new Position(1.5, 64.0, 2.5),
            'Guide',
            self::skin('one'),
        );

        $human->getController()->setSkin(self::skin('two'));

        self::assertSame('two', $human->getSkin()->id);
        self::assertSame(1, $human->skinRevision());
    }

    public function testHumanExposesAuthoritativeEquipmentWithoutAPlayerInventory(): void
    {
        $human = (new HumanEntityFactory())->create(
            '88888888-8888-4888-8888-888888888888',
            56,
            'world',
            new Position(1.5, 64.0, 2.5),
            'Guard',
            self::skin('one'),
        );
        $sword = new ItemStack('minecraft:diamond_sword', 1);
        $helmet = new ItemStack('minecraft:diamond_helmet', 1);
        $shield = new ItemStack('minecraft:shield', 1);

        $human->getEquipment()->setMainHand($sword);
        $human->getEquipment()->setHelmet($helmet);

        self::assertSame($sword, $human->getEquipment()->getMainHand());
        self::assertSame($helmet, $human->getEquipment()->getHelmet());
        self::assertSame($helmet, $human->getEquipment()->getArmorContents()['head']);

        $actions = new PluginActionBuffer();
        $human->attachController($actions);
        $actions->begin();
        $human->getEquipment()->setOffHand($shield);
        $human->getEquipment()->clearArmor();
        self::assertNull($human->getEquipment()->getOffHand());
        self::assertSame($helmet, $human->getEquipment()->getHelmet());
        $actions->discard();
        self::assertNull($human->getEquipment()->getOffHand());
        self::assertSame($helmet, $human->getEquipment()->getHelmet());

        $actions->begin();
        $human->getEquipment()->setOffHand($shield);
        $human->getEquipment()->clearArmor();
        $actions->commit();
        self::assertSame($shield, $human->getEquipment()->getOffHand());
        self::assertNull($human->getEquipment()->getHelmet());
    }

    public function testConvenienceMutationsRollBackWithTheirPluginTransaction(): void
    {
        $human = (new HumanEntityFactory())->create(
            '55555555-5555-4555-8555-555555555555',
            53,
            'world',
            new Position(1.5, 64.0, 2.5),
            'Guide',
            self::skin('one'),
        );
        $actions = new PluginActionBuffer();
        $human->attachController($actions);

        $actions->begin();
        $human->setSkin(self::skin('two'));
        $human->setMotion(new Motion(0.2, 0.4, -0.1));
        $human->setNameTag('Staged');
        $human->setNameTagVisible(true);
        $human->setImmobile(true);
        $human->setInvisible(true);
        $human->setScale(1.25);
        $human->setGravityEnabled(false);
        $human->swingArm();

        self::assertSame('one', $human->getSkin()->id);
        self::assertSame(0.0, $human->getMotion()->x);
        self::assertSame('', $human->nameTag());
        self::assertSame([], $human->drainAnimationRequests());

        $actions->discard();

        self::assertSame('one', $human->getSkin()->id);
        self::assertFalse($human->isNameTagVisible());
        self::assertFalse($human->isImmobile());
        self::assertFalse($human->isInvisible());
        self::assertSame(1.0, $human->scale());
        self::assertTrue($human->isGravityEnabled());
    }

    public function testNavigationRetainsResolvedSameWorldTargetAndDirectMotionCancelsOnCommit(): void
    {
        $world = new World('world', 7);
        $human = (new HumanEntityFactory())->create(
            '66666666-6666-4666-8666-666666666666',
            54,
            'world',
            new Position(1.5, 64.0, 2.5),
            'Guide',
            self::skin('one'),
            30.0,
            10.0,
            $world,
            WorldDimension::OVERWORLD,
        );
        $actions = new PluginActionBuffer();
        $human->attachController($actions);

        $actions->begin();
        $human->navigateTo(new ApiPosition(20.0, 64.0, 20.0), 0.3);
        $actions->commit();

        self::assertEquals(
            new ApiPosition(20.0, 64.0, 20.0, 30.0, 10.0, $world, WorldDimension::OVERWORLD),
            $human->getController()->getNavigationTarget(),
        );

        $actions->begin();
        $human->setMotion(new Motion(0.5, 0.0, 0.0));
        self::assertTrue($human->getController()->isNavigating());
        $actions->discard();
        self::assertTrue($human->getController()->isNavigating());

        $actions->begin();
        $human->setMotion(new Motion(0.5, 0.0, 0.0));
        $actions->commit();
        self::assertFalse($human->getController()->isNavigating());
    }

    public function testNavigationRejectsAnotherWorldLoadOrDimension(): void
    {
        $world = new World('world', 7);
        $human = (new HumanEntityFactory())->create(
            '77777777-7777-4777-8777-777777777777',
            55,
            'world',
            new Position(1.5, 64.0, 2.5),
            'Guide',
            self::skin('one'),
            world: $world,
        );

        foreach ([
            new ApiPosition(2.0, 64.0, 2.0, world: new World('world', 8), dimension: WorldDimension::OVERWORLD),
            new ApiPosition(2.0, 64.0, 2.0, world: $world, dimension: WorldDimension::NETHER),
        ] as $target) {
            try {
                $human->navigateTo($target, 0.3);
                self::fail('A cross-world or cross-dimension navigation target was accepted.');
            } catch (InvalidArgumentException) {
                self::assertFalse($human->getController()->isNavigating());
            }
        }
    }

    public function testSkinRejectsMismatchedRgbaData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Skin('bad', 64, 64, 'too-short', '{"geometry":{"default":"geometry.humanoid.custom"}}');
    }

    public function testSkinAnimationUsesNamedTextureAndExpressionValues(): void
    {
        $animation = new SkinAnimation(
            2,
            2,
            str_repeat("\xff", 16),
            SkinAnimationType::FACE,
            2.5,
            SkinAnimationExpression::BLINKING,
        );

        self::assertSame(SkinAnimationType::FACE, $animation->type);
        self::assertSame(SkinAnimationExpression::BLINKING, $animation->expression);
    }

    public function testSkinRejectsArmSizeOutsideTheWireContract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Skin(
            'bad-arm-size',
            64,
            64,
            str_repeat("\0", 64 * 64 * 4),
            '{"geometry":{"default":"geometry.humanoid.custom"}}',
            armSize: 'unknown',
        );
    }

    public function testSkinRejectsColorOutsideTheWireContract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Skin(
            'bad-color',
            64,
            64,
            str_repeat("\0", 64 * 64 * 4),
            '{"geometry":{"default":"geometry.humanoid.custom"}}',
            skinColor: 'red',
        );
    }

    public function testPlayerSkinEventsCarryOnlyRealPlayerSnapshots(): void
    {
        $old = self::skin('old');
        $new = self::skin('new');
        $replacement = self::skin('replacement');
        $player = new Player(
            'Tester',
            '44444444-4444-4444-8444-444444444444',
            new ApiPosition(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory([], 0),
            skin: $old,
        );
        $before = new PlayerSkinChangeEvent($player, $old, $new);
        $before->setSkin($replacement);
        $after = new PlayerSkinChangedEvent($player, $old, $replacement);
        $reskinned = $player->withSkin($new);

        self::assertSame($old, $player->getSkin());
        self::assertSame($new, $reskinned->getSkin());
        self::assertSame($replacement, $before->skin());
        self::assertSame($replacement, $after->skin);
    }

    private static function skin(string $id): Skin
    {
        return new Skin(
            $id,
            64,
            64,
            str_repeat("\0", 64 * 64 * 4),
            '{"geometry":{"default":"geometry.humanoid.custom"}}',
        );
    }
}
