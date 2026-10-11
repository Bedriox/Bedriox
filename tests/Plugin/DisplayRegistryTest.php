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

use Bedriox\Api\Display\DisplayInteractionType;
use Bedriox\Api\Display\InteractionEntity;
use Bedriox\Api\Display\TextDisplay;
use Bedriox\Api\Entity\Vector3;
use Bedriox\Api\Event\Display\DisplayInteractedEvent;
use Bedriox\Api\Event\Display\DisplayInteractEvent;
use Bedriox\Api\Event\Display\TextDisplayChangedEvent;
use Bedriox\Api\Event\Display\TextDisplayChangeEvent;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Visibility\VisibilityMode;
use Bedriox\Api\Visibility\VisibilityPolicy;
use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use Bedriox\Server\Plugin\Display\DisplayChange;
use Bedriox\Server\Plugin\Display\DisplayRegistry;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DisplayRegistryTest extends TestCase
{
    public function testDisplaysHaveStableIdentityOwnerScopedKeysTagsAndRemoval(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $changes = [];
        $registry = new DisplayRegistry(
            $ownership,
            onChange: static function (TextDisplay $display, DisplayChange $change) use (&$changes): void {
                $changes[] = [$display->getUniqueId(), $change];
            },
        );
        $first = $registry->forOwner('FirstPlugin');
        $second = $registry->forOwner('SecondPlugin');
        $display = $first->createText(self::position(), 'Welcome', 'spawn-welcome');
        $other = $second->createText(self::position(), 'Other', 'spawn-welcome');

        self::assertSame('FirstPlugin', $display->getOwner());
        self::assertSame('spawn-welcome', $display->getKey());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/D', $display->getUniqueId());
        self::assertSame($display, $first->get('spawn-welcome'));
        self::assertSame($display, $first->getByUniqueId($display->getUniqueId()));
        self::assertNull($second->getByUniqueId($display->getUniqueId()));
        self::assertNotSame($display, $other);

        $display->addTag('spawn');
        $display->addTag('spawn');
        $display->addTag('welcome');
        self::assertSame(['spawn', 'welcome'], $display->getTags());
        self::assertTrue($display->hasTag('spawn'));
        self::assertSame([$display], $first->withTag('spawn'));
        $display->removeTag('spawn');
        self::assertSame([], $first->withTag('spawn'));

        $display->remove();
        $display->remove();
        self::assertTrue($display->isRemoved());
        self::assertNull($first->get('spawn-welcome'));
        self::assertNull($registry->getByUniqueId($display->getUniqueId()));
        $lastChange = array_pop($changes);
        self::assertNotNull($lastChange);
        self::assertSame(DisplayChange::REMOVED, $lastChange[1]);

        $this->expectException(LogicException::class);
        $display->setText('After removal');
    }

    public function testVisibilityDefaultsToEveryoneAndAllowsPerPlayerOverrides(): void
    {
        $registry = new DisplayRegistry(new PluginOwnershipRegistry());
        $display = $registry->forOwner('Example')->createText(self::position(), 'Visible');
        $first = self::player('00000000-0000-4000-8000-000000000001');
        $second = self::player('00000000-0000-4000-8000-000000000002');

        self::assertSame(VisibilityMode::AUTOMATIC, $display->getVisibility()->getMode());
        self::assertTrue($display->getVisibility()->isVisibleTo($first));
        $display->getVisibility()->hideFrom($first);
        self::assertFalse($display->getVisibility()->isVisibleTo($first));
        self::assertTrue($display->getVisibility()->isVisibleTo($second));
        self::assertSame([$first->uuid], $display->getVisibility()->getExcludedPlayerUuids());

        $display->getVisibility()->hideFromAll();
        self::assertFalse($display->getVisibility()->isVisibleTo($first));
        self::assertFalse($display->getVisibility()->isVisibleTo($second));
        $display->getVisibility()->showTo($first);
        self::assertTrue($display->getVisibility()->isVisibleTo($first));
        self::assertFalse($display->getVisibility()->isVisibleTo($second));

        $display->getVisibility()->showToAll();
        self::assertTrue($display->getVisibility()->isVisibleTo($first));
        self::assertTrue($display->getVisibility()->isVisibleTo($second));
    }

    public function testTextChangesAreReplaceableCancellableAndDirtyOnlyWhenCommitted(): void
    {
        $events = [];
        $changes = [];
        $registry = new DisplayRegistry(
            new PluginOwnershipRegistry(),
            static function (Event $event) use (&$events): Event {
                $events[] = $event;
                if ($event instanceof TextDisplayChangeEvent && $event->getText() === 'replace') {
                    $event->setText('replacement');
                }
                if ($event instanceof TextDisplayChangeEvent && $event->getText() === 'cancel') {
                    $event->cancel();
                }

                return $event;
            },
            static function (TextDisplay $display, DisplayChange $change) use (&$changes): void {
                $changes[] = $change;
            },
        );
        $display = $registry->forOwner('Example')->createText(self::position(), 'initial');

        $display->setText('initial');
        self::assertCount(1, $changes);
        self::assertSame([], $events);

        $display->setText('replace');
        self::assertSame('replacement', $display->getText());
        $preEvent = array_shift($events);
        $postEvent = array_shift($events);
        self::assertInstanceOf(TextDisplayChangeEvent::class, $preEvent);
        self::assertInstanceOf(TextDisplayChangedEvent::class, $postEvent);
        self::assertSame('initial', $postEvent->previousText);
        self::assertSame('replacement', $postEvent->text);
        self::assertSame(DisplayChange::TEXT, $changes[1]);

        $display->setText('cancel');
        self::assertSame('replacement', $display->getText());
        self::assertCount(1, $events);
        self::assertCount(2, $changes);
    }

    public function testInteractionUsesDefaultDimensionsAndDispatchesResolvedDisplay(): void
    {
        $events = [];
        $registry = new DisplayRegistry(
            new PluginOwnershipRegistry(),
            static function (Event $event) use (&$events): Event {
                $events[] = $event;

                return $event;
            },
        );
        $display = $registry->forOwner('Example')->createText(self::position(), 'Click me', 'clickable');
        $interaction = $display->enableInteraction();
        $player = self::player('00000000-0000-4000-8000-000000000003');

        self::assertSame(InteractionEntity::DEFAULT_WIDTH, $interaction->getWidth());
        self::assertSame(InteractionEntity::DEFAULT_HEIGHT, $interaction->getHeight());
        self::assertSame($interaction, $display->getInteraction());
        self::assertSame($interaction, $registry->interactionByUniqueId($interaction->getUniqueId()));

        self::assertTrue($registry->dispatchInteraction(
            $interaction->getUniqueId(),
            $player,
            DisplayInteractionType::ATTACK,
            EquipmentSlot::MAIN_HAND,
            new Vector3(0.5, 0.75, 0.1),
        ));
        self::assertInstanceOf(DisplayInteractEvent::class, $events[0]);
        self::assertSame($display, $events[0]->display);
        self::assertSame('clickable', $events[0]->display->getKey());
        self::assertInstanceOf(DisplayInteractedEvent::class, $events[1]);

        $interaction->setSize(3.0, 2.0);
        self::assertSame(3.0, $interaction->getWidth());
        self::assertSame(2.0, $interaction->getHeight());
        $interaction->setEnabled(false);
        self::assertFalse($registry->dispatchInteraction(
            $interaction->getUniqueId(),
            $player,
            DisplayInteractionType::INTERACT,
        ));

        $display->disableInteraction();
        $display->disableInteraction();
        self::assertTrue($interaction->isRemoved());
        self::assertNull($display->getInteraction());
        self::assertNull($registry->interactionByUniqueId($interaction->getUniqueId()));
    }

    public function testCancelledOrHiddenInteractionsDoNotPublishPostEvent(): void
    {
        $events = [];
        $registry = new DisplayRegistry(
            new PluginOwnershipRegistry(),
            static function (Event $event) use (&$events): Event {
                $events[] = $event;
                if ($event instanceof DisplayInteractEvent) {
                    $event->cancel();
                }

                return $event;
            },
        );
        $display = $registry->forOwner('Example')->createText(self::position(), 'Click me');
        $interaction = $display->enableInteraction();
        $player = self::player('00000000-0000-4000-8000-000000000004');

        self::assertFalse($registry->dispatchInteraction(
            $interaction->getUniqueId(),
            $player,
            DisplayInteractionType::INTERACT,
        ));
        self::assertCount(1, $events);

        $display->getVisibility()->hideFrom($player);
        self::assertFalse($registry->dispatchInteraction(
            $interaction->getUniqueId(),
            $player,
            DisplayInteractionType::ATTACK,
        ));
        self::assertCount(1, $events);
    }

    public function testInitialVisibilityIsInstalledBeforeCreationIsPublished(): void
    {
        $selected = self::player('00000000-0000-4000-8000-000000000005');
        $other = self::player('00000000-0000-4000-8000-000000000006');
        $creationAudience = null;
        $registry = new DisplayRegistry(
            new PluginOwnershipRegistry(),
            onChange: static function (TextDisplay $display, DisplayChange $change) use (
                &$creationAudience,
                $selected,
                $other,
            ): void {
                if ($change === DisplayChange::CREATED) {
                    $creationAudience = [
                        $display->getVisibility()->isVisibleTo($selected),
                        $display->getVisibility()->isVisibleTo($other),
                    ];
                }
            },
        );

        $display = $registry->forOwner('Example')->createText(
            self::position(),
            'Private',
            visibility: VisibilityPolicy::only($selected),
        );

        self::assertSame([true, false], $creationAudience);
        self::assertSame(VisibilityMode::SELECTED, $display->getVisibility()->getMode());
        self::assertSame([$selected->uuid], $display->getVisibility()->getSelectedPlayerUuids());

        $display->remove();
        self::assertSame(VisibilityMode::HIDDEN, $display->getVisibility()->getMode());
        self::assertSame([], $display->getVisibility()->getSelectedPlayerUuids());
        self::assertFalse($display->getVisibility()->isVisibleTo($selected));

        $this->expectException(LogicException::class);
        $display->getVisibility()->showToAll();
    }

    public function testPluginCleanupRemovesDisplaysAndTheirInteractions(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $registry = new DisplayRegistry($ownership);
        $manager = $registry->forOwner('Example');
        $display = $manager->createText(self::position(), 'Temporary', 'temporary');
        $interaction = $display->enableInteraction();

        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertTrue($display->isRemoved());
        self::assertTrue($interaction->isRemoved());
        self::assertSame([], $manager->getAll());
    }

    public function testExplicitRemoveAllKeepsOwnerCleanupReusable(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $registry = new DisplayRegistry($ownership);
        $manager = $registry->forOwner('Example');
        $first = $manager->createText(self::position(), 'First', 'first');

        $manager->removeAll();
        self::assertTrue($first->isRemoved());
        self::assertSame(1, $ownership->count('Example'));

        $second = $manager->createText(self::position(), 'Second', 'second');
        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertTrue($second->isRemoved());
    }

    public function testRejectsDuplicateKeysAndUnboundedValues(): void
    {
        $manager = (new DisplayRegistry(new PluginOwnershipRegistry()))->forOwner('Example');
        $manager->createText(self::position(), 'First', 'duplicate');

        try {
            $manager->createText(self::position(), 'Second', 'duplicate');
            self::fail('A duplicate owner-scoped display key was accepted.');
        } catch (PluginException) {
            self::addToAssertionCount(1);
        }

        foreach ([
            static fn() => $manager->createText(self::position(), '', 'empty-text'),
            static fn() => $manager->createText(self::position(), 'Text', 'Uppercase'),
            static fn() => $manager->createText(self::position(), str_repeat("line\n", TextDisplay::MAXIMUM_TEXT_LINES), 'lines'),
        ] as $operation) {
            try {
                $operation();
                self::fail('An invalid display value was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $display = $manager->createText(self::position(), 'Bounded', 'bounded');
        $this->expectException(InvalidArgumentException::class);
        $display->enableInteraction(0.0, 1.0);
    }

    private static function position(): Position
    {
        return new Position(
            0.5,
            65.0,
            0.5,
            0.0,
            0.0,
            new World('world', 1),
        );
    }

    private static function player(string $uuid): Player
    {
        return new Player(
            'Player',
            $uuid,
            self::position(),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
