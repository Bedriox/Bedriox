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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\EntityTargetReason;
use Bedriox\Api\Event\Block\BlockBreakEvent;
use Bedriox\Api\Event\Block\BlockBrokenEvent;
use Bedriox\Api\Event\Block\BlockPlaceEvent;
use Bedriox\Api\Event\Entity\EntityEffectAddedEvent;
use Bedriox\Api\Event\Entity\EntityEffectAddEvent;
use Bedriox\Api\Event\Entity\EntityInteractedEvent;
use Bedriox\Api\Event\Entity\EntityTargetChangedEvent;
use Bedriox\Api\Event\Entity\EntityTargetEvent;
use Bedriox\Api\Event\EventPriority;
use Bedriox\Api\Event\Inventory\InventoryChangedEvent;
use Bedriox\Api\Event\Inventory\InventoryChangeEvent;
use Bedriox\Api\Event\Player\PlayerAttackedEvent;
use Bedriox\Api\Event\Player\PlayerAttackEvent;
use Bedriox\Api\Event\Player\PlayerChatBroadcastEvent;
use Bedriox\Api\Event\Player\PlayerChatEvent;
use Bedriox\Api\Event\Player\PlayerDamagedEvent;
use Bedriox\Api\Event\Player\PlayerDamageEvent;
use Bedriox\Api\Event\Player\PlayerDeathEvent;
use Bedriox\Api\Event\Player\PlayerEquipmentChangedEvent;
use Bedriox\Api\Event\Player\PlayerEquipmentChangeEvent;
use Bedriox\Api\Event\Player\PlayerFoodLevelChangedEvent;
use Bedriox\Api\Event\Player\PlayerFoodLevelChangeEvent;
use Bedriox\Api\Event\Player\PlayerJoinEvent;
use Bedriox\Api\Event\Player\PlayerKickCause;
use Bedriox\Api\Event\Player\PlayerKickEvent;
use Bedriox\Api\Event\Player\PlayerLoginEvent;
use Bedriox\Api\Event\Player\PlayerMissSwingEvent;
use Bedriox\Api\Event\Player\PlayerMoveEvent;
use Bedriox\Api\Event\Player\PlayerPreJoinEvent;
use Bedriox\Api\Event\Player\PlayerQuitCause;
use Bedriox\Api\Event\Player\PlayerQuitEvent;
use Bedriox\Api\Event\Player\PlayerRegainedHealthEvent;
use Bedriox\Api\Event\Player\PlayerRegainHealthEvent;
use Bedriox\Api\Event\Player\PlayerRespawnedEvent;
use Bedriox\Api\Event\Player\PlayerRespawnEvent;
use Bedriox\Api\Event\Player\PlayerTeleportedEvent;
use Bedriox\Api\Event\Player\PlayerTeleportEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\Inventory as ApiInventory;
use Bedriox\Api\Player\FoodLevelChangeCause;
use Bedriox\Api\Player\HealthRegainCause;
use Bedriox\Api\Player\Player as ApiPlayer;
use Bedriox\Api\Player\PlayerInteractionType;
use Bedriox\Api\TextFormat;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Potion\PotionEffectDose;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\ArmSwingSource;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerHealed;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PluginGameplayEventBridgeTest extends TestCase
{
    public function testJoinEventProvidesYellowDefaultAndAllowsReplacementOrSuppression(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $player = new Player(
            'session',
            1,
            new PlayerIdentity('identity-one', 'One'),
            new Position(0.0, 64.0, 0.0),
            4,
            0,
            64.0,
        );

        $default = $bridge->joined($player);
        self::assertSame(TextFormat::YELLOW . 'One joined the game' . TextFormat::RESET, $default);

        $dispatcher->register('Example', PlayerJoinEvent::class, static function (PlayerJoinEvent $event): void {
            $event->setJoinMessage('One entered the world!');
        });
        self::assertSame('One entered the world!', $bridge->joined($player));

        [$suppressingDispatcher, $suppressingBridge] = self::bridge();
        $suppressingDispatcher->register('Example', PlayerJoinEvent::class, static function (PlayerJoinEvent $event): void {
            $event->setJoinMessage(null);
        });
        self::assertNull($suppressingBridge->joined($player));
    }

    public function testQuitEventExposesAttributionAndAllowsReplacementOrSuppression(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $player = new Player(
            'session',
            1,
            new PlayerIdentity('identity-one', 'One'),
            new Position(0.0, 64.0, 0.0),
            4,
            0,
            64.0,
        );
        $observed = null;
        $dispatcher->register('Example', PlayerQuitEvent::class, static function (PlayerQuitEvent $event) use (&$observed): void {
            $observed = [$event->cause, $event->reason, $event->actor];
            $event->setQuitMessage('One departed.');
        });

        self::assertSame('One departed.', $bridge->quit(
            $player,
            PlayerQuitCause::KICKED,
            'Griefing',
            'Console',
            TextFormat::YELLOW . 'One left the game' . TextFormat::RESET,
        ));
        self::assertSame([PlayerQuitCause::KICKED, 'Griefing', 'Console'], $observed);

        [$suppressingDispatcher, $suppressingBridge] = self::bridge();
        $suppressingDispatcher->register('Example', PlayerQuitEvent::class, static function (PlayerQuitEvent $event): void {
            $event->setQuitMessage(null);
        });
        self::assertNull($suppressingBridge->quit(
            $player,
            PlayerQuitCause::DISCONNECTED,
            'Disconnected',
            null,
            TextFormat::YELLOW . 'One left the game' . TextFormat::RESET,
        ));
    }

    public function testEntityInteractedDispatchesCommittedHeldItemSnapshot(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $seen = null;
        $dispatcher->register(
            'Example',
            EntityInteractedEvent::class,
            static function (EntityInteractedEvent $event) use (&$seen): void {
                $seen = $event;
            },
        );
        $player = new Player(
            'session',
            1,
            new PlayerIdentity('identity-one', 'One'),
            new Position(0.0, 64.0, 0.0),
            4,
            0,
            64.0,
        );
        $sheep = new SheepEntity(
            EntityUuid::random(),
            2,
            'world',
            new Position(1.0, 64.0, 0.0),
        );

        $bridge->entityInteracted(
            $player,
            $sheep,
            EntityInteractionType::ITEM_INTERACT,
            new InventoryStack('minecraft:red_dye', 3, 9, damage: 7),
        );

        self::assertInstanceOf(EntityInteractedEvent::class, $seen);
        self::assertSame($sheep, $seen->entity);
        self::assertSame(EntityInteractionType::ITEM_INTERACT, $seen->interaction);
        self::assertNotNull($seen->heldItem);
        self::assertSame('minecraft:red_dye', $seen->heldItem->identifier);
        self::assertSame(3, $seen->heldItem->count);
        self::assertSame(7, $seen->heldItem->damage);
    }

    public function testEntityTargetBridgeReturnsAdjustedIntentAndDispatchesCommittedTransition(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $replacement = self::loginPlayerView();
        $committed = null;
        $dispatcher->register(
            'Example',
            EntityTargetEvent::class,
            static function (EntityTargetEvent $event) use ($replacement): void {
                $event->setTarget($replacement);
            },
        );
        $dispatcher->register(
            'Example',
            EntityTargetChangedEvent::class,
            static function (EntityTargetChangedEvent $event) use (&$committed): void {
                $committed = $event;
            },
        );
        $skeleton = new SkeletonEntity(
            EntityUuid::random(),
            2,
            'world',
            new Position(1.0, 64.0, 0.0),
        );

        $intent = $bridge->entityTarget(
            $skeleton,
            null,
            null,
            EntityTargetReason::CLOSEST_PLAYER,
        );
        self::assertSame($replacement, $intent->target());

        $bridge->entityTargetChanged(
            $skeleton,
            null,
            $intent->target(),
            EntityTargetReason::CLOSEST_PLAYER,
        );
        self::assertInstanceOf(EntityTargetChangedEvent::class, $committed);
        self::assertSame($replacement, $committed->target);
    }

    public function testDirectSplashAndLingeringInstantDosesBypassEffectLifecycleAndUseVitalEvents(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $effectAdds = 0;
        $effectAdded = 0;
        $damageCauses = [];
        $regainEvents = 0;
        $dispatcher->register('Example', EntityEffectAddEvent::class, static function () use (&$effectAdds): void {
            ++$effectAdds;
        });
        $dispatcher->register('Example', EntityEffectAddedEvent::class, static function () use (&$effectAdded): void {
            ++$effectAdded;
        });
        $dispatcher->register('Example', PlayerDamageEvent::class, static function (PlayerDamageEvent $event) use (&$damageCauses): void {
            $damageCauses[] = $event->cause;
        });
        $dispatcher->register('Example', PlayerRegainHealthEvent::class, static function () use (&$regainEvents): void {
            ++$regainEvents;
        });

        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $players = (new \ReflectionProperty(WorldSimulation::class, 'players'))->getValue($simulation);
        self::assertInstanceOf(\Bedriox\Server\Player\PlayerRegistry::class, $players);
        $player = $players->player('one');
        self::assertNotNull($player);
        $applyDose = new \ReflectionMethod(WorldSimulation::class, 'applyPotionDoseToPlayer');

        $damaged = $applyDose->invoke(
            $simulation,
            $player,
            new PotionEffectDose(new EffectInstance(EffectType::INSTANT_DAMAGE, 1)),
            EffectCause::SPLASH_POTION,
        );
        self::assertInstanceOf(PlayerDamaged::class, $damaged);
        self::assertSame([DamageCause::Magic->value], $damageCauses);
        self::assertSame(0, $effectAdds);
        self::assertSame(0, $effectAdded);

        $healed = $applyDose->invoke(
            $simulation,
            $player,
            new PotionEffectDose(new EffectInstance(EffectType::INSTANT_HEALTH, 1)),
            EffectCause::LINGERING_POTION,
        );
        self::assertInstanceOf(PlayerHealed::class, $healed);
        self::assertSame(1, $regainEvents);
        self::assertSame(0, $effectAdds);
        self::assertSame(0, $effectAdded);

        $applyDose->invoke(
            $simulation,
            $player,
            new PotionEffectDose(new EffectInstance(EffectType::SPEED, 100)),
            EffectCause::SPLASH_POTION,
        );
        self::assertSame(1, $effectAdds);
        self::assertSame(1, $effectAdded);
    }

    public function testKickEventCanChangeMessagesOrCancelTheRequest(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerKickEvent::class, static function (PlayerKickEvent $event): void {
            self::assertSame(PlayerKickCause::SERVER_POLICY, $event->cause);
            $event->setReason('Changed reason');
            $event->setQuitMessage('Changed quit');
            $event->setDisconnectScreenMessage('Changed screen');
        });
        self::assertSame(
            ['Changed reason', 'Changed quit', 'Changed screen'],
            $bridge->kick(self::loginPlayerView(), PlayerKickCause::SERVER_POLICY, 'Original', null, null),
        );

        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerKickEvent::class, static function (PlayerKickEvent $event): void {
            $event->cancel();
        });
        self::assertNull($bridge->kick(self::loginPlayerView(), PlayerKickCause::PLUGIN, 'Original', null, null));
    }

    public function testLoginEventReturnsTheFinalSynchronousBootstrapDestination(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerLoginEvent::class, static function (PlayerLoginEvent $event): void {
            $event->setDestination(new ApiPosition(24.5, 70.0, -18.25));
            $event->setOrientation(135.0, -15.0);
        });
        $decision = $bridge->login(self::loginPlayerView());

        self::assertTrue($decision->allowed);
        self::assertSame(24.5, $decision->destination->x);
        self::assertSame(70.0, $decision->destination->y);
        self::assertSame(-18.25, $decision->destination->z);
        self::assertSame(135.0, $decision->yaw);
        self::assertSame(-15.0, $decision->pitch);
    }

    public function testCancelledLoginReturnsARejectedDecisionWithoutPublishingJoin(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $joined = 0;
        $dispatcher->register('Example', PlayerLoginEvent::class, static function (PlayerLoginEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', PlayerJoinEvent::class, static function () use (&$joined): void {
            ++$joined;
        });
        $decision = $bridge->login(self::loginPlayerView());

        self::assertFalse($decision->allowed);
        self::assertSame(0, $joined);
    }

    public function testLoginEventRejectsInvalidPluginDestinationAndRestoresEarlierState(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerLoginEvent::class, static function (PlayerLoginEvent $event): void {
            $event->setDestination(new ApiPosition(INF, 64.0, 0.0));
        });
        $view = self::loginPlayerView();
        $decision = $bridge->login($view);

        self::assertTrue($decision->allowed);
        self::assertSame($view->position->x, $decision->destination->x);
        self::assertSame($view->position->y, $decision->destination->y);
        self::assertSame($view->position->z, $decision->destination->z);
    }

    public function testCancelledJoinUsesTheExistingAuthoritativeRejection(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $joined = 0;
        $dispatcher->register('Example', PlayerPreJoinEvent::class, static function (PlayerPreJoinEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', PlayerJoinEvent::class, static function () use (&$joined): void {
            ++$joined;
        });
        [$simulation, $factory] = self::simulation($bridge);

        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(CommandRejected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame(0, $joined);
        self::assertSame([], $simulation->snapshot()->players);
    }

    public function testChatCanBeChangedBeforeCommitAndPublishesAnImmutablePostEvent(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $broadcast = null;
        $dispatcher->register('Example', PlayerChatEvent::class, static function (PlayerChatEvent $event): void {
            $event->setMessage('changed');
        });
        $dispatcher->register('Example', PlayerChatBroadcastEvent::class, static function (PlayerChatBroadcastEvent $event) use (&$broadcast): void {
            $broadcast = $event->message;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->chat('one', 1, 'original'));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(ChatBroadcast::class, $event);
        self::assertSame('changed', $event->message);
        self::assertSame('changed', $broadcast);
    }

    public function testCancelledChatIsNotBroadcastAndStillConsumesItsAntiReplaySequence(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerChatEvent::class, static function (PlayerChatEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->chat('one', 1, 'blocked'));
        $cancelled = $simulation->tick()->events[0];
        $simulation->enqueue($factory->chat('one', 1, 'replay'));
        $replay = $simulation->tick()->events[0];

        self::assertInstanceOf(CommandRejected::class, $cancelled);
        self::assertSame('plugin_cancelled', $cancelled->reason);
        self::assertInstanceOf(CommandRejected::class, $replay);
        self::assertSame('stale_chat_sequence', $replay->reason);
    }

    public function testCancelledBlockBreakCorrectsThePredictionWithoutMutatingWorld(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', BlockBreakEvent::class, static function (BlockBreakEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', BlockBrokenEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $factory, $world, $palette] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $position = new BlockPosition(1, 63, 0);

        $simulation->enqueue($factory->breakBlock('one', 1, BlockBreakAction::Start, $position, 1));
        $simulation->tick();
        $simulation->enqueue($factory->breakBlock('one', 2, BlockBreakAction::Complete, $position, 1));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(BlockChanged::class, $event);
        self::assertSame(['one'], $event->recipients());
        self::assertTrue($event->stopBreaking);
        self::assertSame($palette->grassBlock->value, $world->blockStateAt(1, 63, 0)->value);
        self::assertSame(0, $postEvents);
    }

    public function testCancelledMovementReturnsAnAuthoritativeCorrection(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerMoveEvent::class, static function (PlayerMoveEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory, $world] = self::simulation($bridge);
        self::retainOriginCollisionTerrain($world);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->move('one', 1, 0.1, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(MovementCorrected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame(0.0, $event->authoritativePlayer->position->x);
        self::assertSame(1, $event->authoritativePlayer->movementSequence);
    }

    public function testTeleportCanBeChangedOrCancelledAndPublishesCommittedState(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $post = null;
        $dispatcher->register('Example', PlayerTeleportEvent::class, static function (PlayerTeleportEvent $event): void {
            self::assertSame(0.0, $event->from->x);
            $event->setDestination(new ApiPosition(8.0, 63.0, -4.0));
            $event->setOrientation(120.0, -30.0);
        });
        $dispatcher->register('Example', PlayerTeleportedEvent::class, static function (PlayerTeleportedEvent $event) use (&$post): void {
            $post = [$event->from, $event->destination, $event->yaw, $event->pitch];
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->teleport('one', 2.0, 70.0, 3.0));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(MovementCorrected::class, $event);
        self::assertSame('plugin_teleport', $event->reason);
        self::assertSame(8.0, $event->authoritativePlayer->position->x);
        self::assertSame(63.0, $event->authoritativePlayer->position->y);
        self::assertSame(120.0, $event->authoritativePlayer->yaw);
        self::assertSame(-30.0, $event->authoritativePlayer->pitch);
        self::assertNotNull($post);
        self::assertSame(0.0, $post[0]->x);
        self::assertSame(8.0, $post[1]->x);

        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerTeleportEvent::class, static function (PlayerTeleportEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->teleport('one', 2.0, 70.0, 3.0));

        $cancelled = $simulation->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $cancelled);
        self::assertSame('plugin_cancelled', $cancelled->reason);
        self::assertSame(0.0, $simulation->snapshot()->players[0]->position->x);
    }

    public function testCancelledPlacementRepairsThePredictedBlockAndPreservesInventory(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', BlockPlaceEvent::class, static function (BlockPlaceEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory, $world, $palette] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        self::assertTrue($simulation->enqueueGiveItem('identity-one', 'minecraft:grass_block', 64));
        $simulation->tick();

        $simulation->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(1, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        ));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(BlockPlacementCorrected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame($palette->air->value, $world->blockStateAt(1, 64, 0)->value);
        self::assertSame(64, $event->heldStack?->count);
    }

    public function testCancelledInventorySelectionReturnsTheCurrentAuthoritativeSlot(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', InventoryChangeEvent::class, static function (InventoryChangeEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', InventoryChangedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->selectHotbarSlot('one', 1));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(HeldItemChanged::class, $event);
        self::assertSame(0, $event->hotbarSlot);
        self::assertSame(0, $postEvents);
    }

    public function testPluginApiRequestsCommitThroughTheAuthoritativeQueue(): void
    {
        [, $bridge] = self::bridge();
        [$simulation, , $world, $palette] = self::simulation($bridge);
        $simulation->enqueue((new SimulationCommandFactory())->join('one', 'identity-one', 'One'));
        $simulation->tick();

        self::assertTrue($simulation->enqueuePluginMessage('identity-one', 'hello'));
        self::assertTrue($simulation->enqueuePluginTeleport('identity-one', new Position(2.0, 64.0, 0.0)));
        self::assertTrue($simulation->enqueuePluginBlock('Example', new BlockPosition(1, 63, 0), 'minecraft:air'));
        self::assertTrue($simulation->enqueuePluginInventorySlot(
            'identity-one',
            1,
            new InventoryStack('minecraft:grass_block', 3, 1, $palette->grassBlock),
        ));

        $events = $simulation->tick()->events;
        self::assertInstanceOf(ChatBroadcast::class, $events[0]);
        self::assertSame(['one'], $events[0]->recipients());
        self::assertInstanceOf(MovementCorrected::class, $events[1]);
        self::assertSame(2.0, $events[1]->authoritativePlayer->position->x);
        self::assertInstanceOf(BlockChanged::class, $events[2]);
        self::assertSame($palette->air->value, $world->blockStateAt(1, 63, 0)->value);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $events[3]);
        self::assertSame(3, $events[3]->mainInventory[1]?->count);
    }

    public function testPluginInventoryBulkMutationsAreAtomicAndEventAware(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', InventoryChangedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $factory, , $palette] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $contents = array_fill(0, 36, null);
        $contents[3] = new InventoryStack('minecraft:grass_block', 8, 1, $palette->grassBlock);
        self::assertTrue($simulation->enqueuePluginInventoryContents('identity-one', $contents));
        $set = $simulation->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $set);
        self::assertSame([3], array_map(static fn($slot): int => $slot->slot, $set->affectedSlots));
        self::assertSame(8, $set->mainInventory[3]?->count);

        self::assertTrue($simulation->enqueuePluginInventoryRemoval(
            'identity-one',
            new InventoryStack('minecraft:grass_block', 3, 1, $palette->grassBlock),
        ));
        $remove = $simulation->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $remove);
        self::assertSame(5, $remove->mainInventory[3]?->count);
        self::assertSame(2, $postEvents);

        self::assertTrue($simulation->enqueueGiveItem('identity-one', 'minecraft:apple', 2));
        $given = $simulation->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $given);
        self::assertSame(2, array_sum(array_map(
            static fn(?InventoryStack $stack): int => $stack?->identifier === 'minecraft:apple' ? $stack->count : 0,
            $given->mainInventory,
        )));
        self::assertSame(3, $postEvents);

        $dispatcher->register('Example', InventoryChangeEvent::class, static function (InventoryChangeEvent $event): void {
            $event->cancel();
        }, EventPriority::HIGHEST);
        self::assertTrue($simulation->enqueuePluginInventoryContents('identity-one', array_fill(0, 36, null)));
        $rejected = $simulation->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $rejected);
        self::assertTrue($simulation->enqueue($factory->syncInventory('one')));
        $synced = $simulation->tick()->events[0];
        if (!$synced instanceof InventoryStackRequestProcessed) {
            self::fail('Expected the authoritative inventory synchronization event.');
        }
        self::assertSame(5, $synced->mainInventory[3]?->count);
    }

    public function testPluginArmorContentsCommitAtomicallyAfterEquipmentEvents(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postSlots = [];
        $dispatcher->register('Example', PlayerEquipmentChangeEvent::class, static function (PlayerEquipmentChangeEvent $event): void {
            if ($event->slot === EquipmentSlot::CHEST) {
                $event->cancel();
            }
        });
        $dispatcher->register('Example', PlayerEquipmentChangedEvent::class, static function (PlayerEquipmentChangedEvent $event) use (&$postSlots): void {
            $postSlots[] = $event->slot;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $armor = [
            self::inventoryStack($items, 'minecraft:iron_helmet'),
            self::inventoryStack($items, 'minecraft:iron_chestplate'),
            null,
            null,
        ];

        self::assertTrue($simulation->enqueuePluginArmorContents('identity-one', $armor));
        self::assertInstanceOf(CommandRejected::class, $simulation->tick()->events[0]);
        self::assertSame([], $postSlots);
    }

    public function testDamageCanBeChangedBeforeCommitAndPublishesHealthPostEvents(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postDamage = null;
        $dispatcher->register('Example', PlayerDamageEvent::class, static function (PlayerDamageEvent $event): void {
            $event->setDamage(4.0);
        });
        $dispatcher->register('Example', PlayerDamagedEvent::class, static function (PlayerDamagedEvent $event) use (&$postDamage): void {
            $postDamage = [$event->damage, $event->player->health];
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($factory->damage('one', 8.0)));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(PlayerDamaged::class, $event);
        self::assertSame(4.0, $event->damage);
        self::assertSame(16.0, $event->player->health);
        self::assertSame([4.0, 16.0], $postDamage);
    }

    public function testAttackEventsCanChangeDamageAndObserveCommittedTargetState(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $post = null;
        $dispatcher->register('Example', PlayerAttackEvent::class, static function (PlayerAttackEvent $event): void {
            self::assertSame(PlayerInteractionType::ATTACK, $event->interaction);
            self::assertSame('One', $event->attacker->name);
            self::assertSame('Two', $event->target->name);
            $event->setDamage(3.0);
        });
        $dispatcher->register('Example', PlayerAttackedEvent::class, static function (PlayerAttackedEvent $event) use (&$post): void {
            $post = [$event->damage, $event->target->health, $event->interaction];
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One', 1));
        $simulation->enqueue($factory->join('two', 'identity-two', 'Two', 2));
        $simulation->tick();
        $simulation->enqueue($factory->move('two', 1, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::WALKING));
        $simulation->tick();

        $simulation->enqueue($factory->attack('one', 2, 0));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(PlayerDamaged::class, $event);
        self::assertSame(3.0, $event->damage);
        self::assertSame([3.0, 17.0, PlayerInteractionType::ATTACK], $post);
    }

    public function testCancelledAttackLeavesTargetHealthUntouched(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerAttackEvent::class, static function (PlayerAttackEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One', 1));
        $simulation->enqueue($factory->join('two', 'identity-two', 'Two', 2));
        $simulation->tick();
        $simulation->enqueue($factory->move('two', 1, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::WALKING));
        $simulation->tick();
        $simulation->enqueue($factory->attack('one', 2, 0));

        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(CommandRejected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame(20.0, $simulation->snapshot()->players[1]->health);
    }

    public function testCancelledDamageDoesNotMutateHealthOrPublishPostEvent(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', PlayerDamageEvent::class, static function (PlayerDamageEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', PlayerDamagedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->damage('one', 8.0));

        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(CommandRejected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame(20.0, $simulation->snapshot()->players[0]->health);
        self::assertSame(0, $postEvents);
    }

    public function testDeathAndRespawnEventsObserveCommittedStateAndMaySelectDestination(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $deathHealth = null;
        $deathCause = null;
        $deathDamage = null;
        $deathMessage = null;
        $respawnHealth = null;
        $dispatcher->register('Example', PlayerDeathEvent::class, static function (PlayerDeathEvent $event) use (&$deathHealth, &$deathCause, &$deathDamage, &$deathMessage): void {
            $deathHealth = $event->player->health;
            $deathCause = $event->cause;
            $deathDamage = $event->damage;
            self::assertNull($event->killer);
            self::assertFalse($event->keepsInventory());
            $event->setKeepInventory(true);
            self::assertInstanceOf(TranslatableMessage::class, $event->deathMessage());
            $event->setDeathMessage('One died while testing');
            $event->setDeathScreenMessage(null);
            $deathMessage = $event->deathMessage();
        });
        $dispatcher->register('Example', PlayerRespawnEvent::class, static function (PlayerRespawnEvent $event): void {
            $event->setPosition(new ApiPosition(2.0, 64.0, 3.0));
        });
        $dispatcher->register('Example', PlayerRespawnedEvent::class, static function (PlayerRespawnedEvent $event) use (&$respawnHealth): void {
            $respawnHealth = $event->player->health;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->damage('one', 20.0));
        $deathEvents = $simulation->tick()->events;

        self::assertSame(0.0, $deathHealth);
        self::assertSame('plugin', $deathCause);
        self::assertSame(20.0, $deathDamage);
        self::assertSame('One died while testing', $deathMessage);
        self::assertInstanceOf(PlayerDied::class, $deathEvents[1]);
        self::assertSame('One died while testing', $deathEvents[1]->deathMessage);
        self::assertNull($deathEvents[1]->deathScreenMessage);
        $simulation->enqueue($factory->respawn('one'));
        $event = $simulation->tick()->events[0];
        self::assertInstanceOf(PlayerRespawned::class, $event);
        self::assertSame(2.0, $event->player->position->x);
        self::assertSame(3.0, $event->player->position->z);
        self::assertSame(20.0, $respawnHealth);
    }

    public function testDeathListenerMayRetainTheAuthoritativeInventory(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerDeathEvent::class, static function (PlayerDeathEvent $event): void {
            $event->setKeepInventory(true);
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        self::assertTrue($simulation->enqueueGiveItem('identity-one', 'minecraft:apple', 2));
        $simulation->tick();
        $simulation->enqueue($factory->damage('one', 20.0));
        $simulation->tick();
        $simulation->enqueue($factory->acknowledgeRespawn('one'));

        $events = $simulation->tick()->events;
        $respawned = array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof PlayerRespawned,
        ));

        self::assertCount(1, $respawned);
        $stack = $respawned[0]->inventory[0];
        self::assertNotNull($stack);
        self::assertSame('minecraft:apple', $stack->identifier);
        self::assertSame(2, $stack->count);
    }

    public function testFaultyDeathListenerRestoresBothMessagesBeforeLaterListeners(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $observedChat = null;
        $observedScreen = null;
        $dispatcher->register('Example', PlayerDeathEvent::class, static function (PlayerDeathEvent $event): void {
            $event->setDeathMessage('broken chat');
            $event->setDeathScreenMessage('broken screen');
            throw new RuntimeException('listener failed');
        }, EventPriority::LOW);
        $dispatcher->register('Example', PlayerDeathEvent::class, static function (PlayerDeathEvent $event) use (&$observedChat, &$observedScreen): void {
            $observedChat = $event->deathMessage();
            $observedScreen = $event->deathScreenMessage();
        }, EventPriority::HIGH);
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->damage('one', 20.0));

        $events = $simulation->tick()->events;

        self::assertInstanceOf(TranslatableMessage::class, $observedChat);
        self::assertSame('death.attack.generic', $observedChat->key);
        self::assertInstanceOf(TranslatableMessage::class, $observedScreen);
        self::assertSame('death.attack.generic', $observedScreen->key);
        self::assertInstanceOf(PlayerDied::class, $events[1]);
        self::assertEquals($observedChat, $events[1]->deathMessage);
        self::assertEquals($observedScreen, $events[1]->deathScreenMessage);
    }

    public function testPvpDeathReportsFinalIncomingDamageAndKiller(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $deathDamage = null;
        $killerIdentity = null;
        $dispatcher->register('Example', PlayerAttackEvent::class, static function (PlayerAttackEvent $event): void {
            $event->setDamage(100.0);
        });
        $dispatcher->register('Example', PlayerDeathEvent::class, static function (PlayerDeathEvent $event) use (&$deathDamage, &$killerIdentity): void {
            $deathDamage = $event->damage;
            $killerIdentity = $event->killer?->uuid;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One', 1));
        $simulation->enqueue($factory->join('two', 'identity-two', 'Two', 2));
        $simulation->tick();
        $simulation->enqueue($factory->move('two', 1, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::WALKING));
        $simulation->tick();
        $simulation->enqueue($factory->attack('one', 2, 0));

        $simulation->tick();

        self::assertSame(100.0, $deathDamage);
        self::assertSame('identity-one', $killerIdentity);
    }

    public function testNaturalRegenerationRunsThroughOrderedPluginEvents(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $order = [];
        $dispatcher->register('Example', PlayerRegainHealthEvent::class, static function (PlayerRegainHealthEvent $event) use (&$order): void {
            $order[] = 'health-before';
            self::assertSame(HealthRegainCause::SATURATION, $event->cause);
            $event->setAmount(0.5);
        });
        $dispatcher->register('Example', PlayerFoodLevelChangeEvent::class, static function (PlayerFoodLevelChangeEvent $event) use (&$order): void {
            $order[] = 'food-before';
            self::assertSame(FoodLevelChangeCause::REGENERATION, $event->cause);
        });
        $dispatcher->register('Example', PlayerRegainedHealthEvent::class, static function (PlayerRegainedHealthEvent $event) use (&$order): void {
            $order[] = 'health-after';
            self::assertSame(0.5, $event->amount);
        });
        $dispatcher->register('Example', PlayerFoodLevelChangedEvent::class, static function (PlayerFoodLevelChangedEvent $event) use (&$order): void {
            $order[] = 'food-after';
            self::assertSame(FoodLevelChangeCause::REGENERATION, $event->cause);
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->damage('one', 2.0));
        $simulation->tick();

        $events = [];
        for ($tick = 0; $tick < 80; ++$tick) {
            $events = $simulation->tick()->events;
            if ($events !== []) {
                break;
            }
        }

        self::assertInstanceOf(PlayerHealed::class, $events[0] ?? null);
        self::assertSame(18.5, $events[0]->player->health);
        self::assertSame(['health-before', 'food-before', 'health-after', 'food-after'], $order);
    }

    public function testPluginCanCancelNaturalRegenerationWithoutChargingNutrition(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerRegainHealthEvent::class, static function (PlayerRegainHealthEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->damage('one', 2.0));
        $simulation->tick();

        for ($tick = 0; $tick < 80; ++$tick) {
            $simulation->tick();
        }

        $player = $simulation->snapshot()->players[0];
        self::assertSame(18.0, $player->health);
        self::assertSame(20.0, $player->food);
        self::assertSame(20.0, $player->saturation);
        self::assertSame(0.0, $player->exhaustion);
    }

    public function testPluginCanCancelAMissedSwing(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerMissSwingEvent::class, static function (PlayerMissSwingEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->swingArm('one', ArmSwingSource::Missed));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(CommandRejected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
    }

    public function testPlayerProjectionCarriesResolvedWorldAndOrientation(): void
    {
        [, $bridge] = self::bridge();
        $handle = new \Bedriox\Api\World\World('world', 3);
        $bridge = $bridge->withWorldResolver(
            static fn(string $worldId): ?\Bedriox\Api\World\World => $worldId === 'world' ? $handle : null,
        );
        $player = new \Bedriox\Server\Player\Player(
            'session',
            1,
            new \Bedriox\Server\Player\PlayerIdentity('identity-one', 'One'),
            new Position(5.0, 70.0, -2.0),
            4,
            0,
            64.0,
            worldName: 'world',
            dimension: WorldDimension::END,
        );
        $player->movement->yaw = 120.0;
        $player->movement->pitch = -25.0;

        $view = $bridge->playerView($player);

        self::assertTrue($view->position->isResolved());
        self::assertSame($handle, $view->position->world);
        self::assertSame(120.0, $view->position->yaw);
        self::assertSame(-25.0, $view->position->pitch);
        self::assertSame(WorldDimension::END, $view->position->dimension);
    }

    /** @return array{EventDispatcher, PluginGameplayEventBridge} */
    private static function bridge(): array
    {
        $control = new GameplayEventRuntimeControl();
        $dispatcher = new EventDispatcher(
            $control,
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );

        return [$dispatcher, new PluginGameplayEventBridge($dispatcher)];
    }

    private static function loginPlayerView(): ApiPlayer
    {
        return new ApiPlayer(
            'One',
            'identity-one',
            new ApiPosition(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new ApiInventory(array_fill(0, 36, null), 0),
        );
    }

    /** @return array{WorldSimulation, SimulationCommandFactory, World, FixedFlatBlockPalette} */
    private static function simulation(PluginGameplayEventBridge $bridge): array
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('plugin-events', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(8),
        );

        $data = BedrockDataSet::bundled();
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );

        return [
            new WorldSimulation(
                blockWorld: $world,
                blockPalette: $palette,
                itemCatalog: $items,
                blockStateRegistry: $registry,
                pluginEvents: $bridge,
            ),
            new SimulationCommandFactory(),
            $world,
            $palette,
        ];
    }

    private static function inventoryStack(ItemCatalog $catalog, string $identifier): InventoryStack
    {
        self::assertTrue($catalog->has($identifier));

        return new InventoryStack($identifier, 1, 1);
    }

    private static function retainOriginCollisionTerrain(World $world): void
    {
        foreach ([-1, 0] as $chunkX) {
            foreach ([-1, 0] as $chunkZ) {
                $world->retainChunk(new ChunkPosition($chunkX, $chunkZ));
            }
        }
    }
}

final class GameplayEventRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'Example';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
