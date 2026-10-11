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
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Visibility\VisibilityMode;
use Bedriox\Api\Visibility\VisibilityPolicy;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\Human\HumanEntityFactory;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class EntityVisibilityTest extends TestCase
{
    public function testVisibilityUsesAuthenticatedUuidsWithoutRetainingPlayers(): void
    {
        $human = $this->human();
        $first = self::player('First', '11111111-1111-4111-8111-111111111111');
        $sameIdentity = self::player('Renamed', '11111111-1111-4111-8111-111111111111');
        $other = self::player('Other', '22222222-2222-4222-8222-222222222222');

        self::assertSame(VisibilityMode::AUTOMATIC, $human->getVisibility()->getMode());
        self::assertTrue($human->getVisibility()->isVisibleTo($other));

        $human->getVisibility()->hideFrom($first);
        self::assertSame(VisibilityMode::EXCLUDED, $human->getVisibility()->getMode());
        self::assertSame([$first->uuid], $human->getVisibility()->getExcludedPlayerUuids());
        self::assertFalse($human->getVisibility()->isVisibleTo($first));
        self::assertTrue($human->getVisibility()->isVisibleTo($other));
        $human->getVisibility()->showTo($first);
        self::assertTrue($human->getVisibility()->isVisibleTo($first));

        $human->getVisibility()->showOnlyTo($first);

        self::assertSame(VisibilityMode::SELECTED, $human->getVisibility()->getMode());
        self::assertSame([$first->uuid], $human->getVisibility()->getSelectedPlayerUuids());
        self::assertTrue($human->getVisibility()->isVisibleTo($sameIdentity));
        self::assertFalse($human->getVisibility()->isVisibleTo($other));

        $human->getVisibility()->showTo($other);
        self::assertTrue($human->getVisibility()->isVisibleTo($other));
        $human->getVisibility()->hideFrom($first);
        self::assertFalse($human->getVisibility()->isVisibleTo($first));

        $human->getVisibility()->showToAll();
        self::assertTrue($human->getVisibility()->isVisibleTo($first));
        self::assertSame([], $human->getVisibility()->getSelectedPlayerUuids());
        $human->getVisibility()->hideFromAll();
        self::assertFalse($human->getVisibility()->isVisibleTo($first));
    }

    public function testInitialPolicyAndPluginTransactionAreAppliedAtomically(): void
    {
        $human = $this->human();
        $selected = self::player('Selected', '33333333-3333-4333-8333-333333333333');
        $other = self::player('Other', '44444444-4444-4444-8444-444444444444');
        $human->applyInitialVisibility(VisibilityPolicy::only($selected));

        self::assertTrue($human->visibilityAllows($selected->uuid));
        self::assertFalse($human->visibilityAllows($other->uuid));

        $actions = new PluginActionBuffer();
        $human->attachController($actions);
        $actions->begin();
        $human->getVisibility()->showOnlyTo($other);
        self::assertTrue($human->getVisibility()->isVisibleTo($selected));
        $actions->discard();
        self::assertTrue($human->getVisibility()->isVisibleTo($selected));

        $actions->begin();
        $human->getVisibility()->showOnlyTo($other);
        $actions->commit();
        self::assertFalse($human->getVisibility()->isVisibleTo($selected));
        self::assertTrue($human->getVisibility()->isVisibleTo($other));
    }

    public function testPolicyRejectsInvalidOrMismatchedUuidSelections(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VisibilityPolicy(VisibilityMode::AUTOMATIC, ['not-a-uuid']);
    }

    public function testChangingModeClearsTheOtherModesIdentitySet(): void
    {
        $human = $this->human();
        $first = self::player('First', '66666666-6666-4666-8666-666666666666');
        $second = self::player('Second', '77777777-7777-4777-8777-777777777777');
        $visibility = $human->getVisibility();

        $visibility->showOnlyTo($first);
        $visibility->setMode(VisibilityMode::EXCLUDED);
        self::assertSame([], $visibility->getSelectedPlayerUuids());
        self::assertSame([], $visibility->getExcludedPlayerUuids());
        self::assertTrue($visibility->isVisibleTo($first));
        self::assertTrue($visibility->isVisibleTo($second));

        $visibility->hideFrom($first);
        self::assertSame([$first->uuid], $visibility->getExcludedPlayerUuids());
        $visibility->setMode(VisibilityMode::SELECTED);
        self::assertSame([], $visibility->getExcludedPlayerUuids());
        self::assertSame([], $visibility->getSelectedPlayerUuids());
        self::assertFalse($visibility->isVisibleTo($first));
        self::assertFalse($visibility->isVisibleTo($second));
    }

    public function testRemovalClearsIdentityStateAndClosesMutationLifecycle(): void
    {
        $human = $this->human();
        $selected = self::player('Selected', '55555555-5555-4555-8555-555555555555');
        $human->getVisibility()->showOnlyTo($selected);

        $human->remove();

        self::assertSame(VisibilityMode::HIDDEN, $human->getVisibility()->getMode());
        self::assertSame([], $human->getVisibility()->getSelectedPlayerUuids());
        self::assertSame([], $human->getVisibility()->getExcludedPlayerUuids());
        self::assertFalse($human->getVisibility()->isVisibleTo($selected));

        $this->expectException(LogicException::class);
        $human->getVisibility()->showToAll();
    }

    private function human(): \Bedriox\Server\Entity\Human\HumanEntity
    {
        return (new HumanEntityFactory())->create(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            70,
            'world',
            new Position(0.5, 64.0, 0.5),
            'Guide',
            new Skin(
                'guide',
                64,
                64,
                str_repeat("\0", 64 * 64 * 4),
                '{"geometry":{"default":"geometry.humanoid.custom"}}',
            ),
        );
    }

    private static function player(string $name, string $uuid): Player
    {
        return new Player(
            $name,
            $uuid,
            new ApiPosition(0.5, 64.0, 0.5),
            0.0,
            0.0,
            false,
            false,
            new Inventory([], 0),
        );
    }
}
