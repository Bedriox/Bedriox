<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\TranslatableMessage;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\Command\AttackPlayer;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Event\PlayerMotionChanged;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;

final class PlayerCombatTest extends TestCase
{
    public function testAttackAppliesAuthoritativeDamageAndKnockback(): void
    {
        [$world, $factory] = self::twoPlayers();

        self::assertTrue($world->enqueue($factory->attack('one', 2, 0)));
        $events = $world->tick()->events;

        self::assertCount(3, $events);
        self::assertInstanceOf(PlayerDamaged::class, $events[0]);
        self::assertSame('two', $events[0]->player->sessionId);
        self::assertSame(1.0, $events[0]->damage);
        self::assertSame(19.0, $events[0]->player->health);
        self::assertInstanceOf(PlayerKnockedBack::class, $events[1]);
        self::assertSame(0.0, $events[1]->motionX);
        self::assertSame(0.4, $events[1]->motionY);
        self::assertSame(0.4, $events[1]->motionZ);
        self::assertInstanceOf(ArmSwung::class, $events[2]);
        self::assertSame('one', $events[2]->ownerSessionId);
        self::assertSame(19.0, $world->snapshot()->players[1]->health);
    }

    public function testArmorReducesCombatDamageAndLosesAuthoritativeDurability(): void
    {
        $factory = new SimulationCommandFactory();
        $data = BedrockDataSet::bundled();
        $items = ItemCatalog::vanilla($data->itemNetworkRegistry());
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new WorldSimulation(
            blockPalette: $palette,
            blockStateRegistry: $states,
            itemCatalog: $items,
        );
        $targetBootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity-two', 'Two'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([], 0, armor: [
                new PlayerInventoryEntry(
                    1,
                    new PlayerInventoryStackState('minecraft:diamond_chestplate', 1),
                ),
            ]),
            0,
            0,
        );
        $world->enqueue($factory->join('one', 'identity-one', 'One', 1));
        $world->enqueue($factory->join('two', 'identity-two', 'Two', 2, $targetBootstrap));
        $world->tick();
        $world->enqueue($factory->move('two', 1, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::WALKING));
        $world->tick();
        $world->enqueue($factory->move('two', 2, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::STOPPED));
        $world->tick();
        $world->enqueue($factory->attack('one', 2, 0));
        $events = $world->tick()->events;

        self::assertInstanceOf(PlayerDamaged::class, $events[0]);
        self::assertEqualsWithDelta(0.68, $events[0]->damage, 0.000_001);
        self::assertTrue($events[0]->equipmentChanged);
        self::assertSame(1, $events[0]->player->armor[1]?->damage);
    }

    public function testSuccessfulAttackDamagesAndBreaksTheAuthoritativeHeldTool(): void
    {
        $factory = new SimulationCommandFactory();
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $world = new WorldSimulation(itemCatalog: $items);
        $world->enqueue($factory->join('one', 'identity-one', 'One', 1));
        $world->enqueue($factory->join('two', 'identity-two', 'Two', 2));
        $world->tick();
        self::assertTrue($world->enqueueGiveItem('identity-one', 'minecraft:wooden_sword', 1, 59));
        $world->tick();
        $world->enqueue($factory->move('two', 1, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::WALKING));
        $world->tick();
        $world->enqueue($factory->move('two', 2, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::STOPPED));
        $world->tick();

        $world->enqueue($factory->attack('one', 2, 0));
        $events = [...$world->tick()->events, ...$world->tick()->events];

        $held = array_values(array_filter($events, static fn(object $event): bool => $event instanceof HeldItemChanged));
        self::assertCount(1, $held);
        self::assertNull($held[0]->stack);
        self::assertNull($world->pluginPlayer('identity-one')?->inventory->stackAt(0));
    }

    public function testKnockbackComposesAcceptedMotionAndPreservesTheExactClientTick(): void
    {
        [$world, $factory] = self::twoPlayers();
        $world->enqueue($factory->move(
            session: 'two',
            sequence: 3,
            x: 0.0,
            y: 64.0,
            z: 2.1,
            yaw: 180.0,
            pitch: 0.0,
            mode: MovementMode::WALKING,
            deltaX: 0.2,
            deltaY: 0.0,
            deltaZ: -0.2,
            clientTick: new ClientInputTick(0x80000000, 25),
        ));
        $world->tick();

        $world->enqueue($factory->attack('one', 2, 0));
        $events = $world->tick()->events;

        self::assertInstanceOf(PlayerKnockedBack::class, $events[1]);
        self::assertEqualsWithDelta(0.0, $events[1]->motionX, 0.000_001);
        self::assertEqualsWithDelta(0.45, $events[1]->motionZ, 0.000_001);
        self::assertSame(0.4, $events[1]->motionY);
        self::assertSame(0x80000000, $events[1]->clientTick->high);
        self::assertSame(25, $events[1]->clientTick->low);
    }

    public function testKnockbackNormalizesDiagonalAndUsesFacingForCoincidentPlayers(): void
    {
        $factory = new SimulationCommandFactory();
        $diagonal = new WorldSimulation();
        $diagonal->enqueue($factory->join('one', 'diagonal-one', 'One', 1));
        $diagonal->enqueue($factory->join('two', 'diagonal-two', 'Two', 2));
        $diagonal->tick();
        $diagonal->enqueue($factory->move('two', 1, 2.0, 64.0, 2.0, 180.0, 0.0, MovementMode::WALKING));
        $diagonal->tick();
        $diagonal->enqueue($factory->move('two', 2, 2.0, 64.0, 2.0, 180.0, 0.0, MovementMode::STOPPED));
        $diagonal->tick();
        $diagonal->enqueue($factory->attack('one', 2, 0));
        $diagonalEvents = $diagonal->tick()->events;
        self::assertInstanceOf(PlayerKnockedBack::class, $diagonalEvents[1]);
        self::assertEqualsWithDelta(0.2828427, $diagonalEvents[1]->motionX, 0.000_001);
        self::assertEqualsWithDelta(0.2828427, $diagonalEvents[1]->motionZ, 0.000_001);

        $coincident = new WorldSimulation();
        $coincident->enqueue($factory->join('one', 'coincident-one', 'One', 1));
        $coincident->enqueue($factory->join('two', 'coincident-two', 'Two', 2));
        $coincident->tick();
        $coincident->enqueue($factory->attack('one', 2, 0));
        $coincidentEvents = $coincident->tick()->events;
        self::assertInstanceOf(PlayerKnockedBack::class, $coincidentEvents[1]);
        self::assertSame(0.0, $coincidentEvents[1]->motionX);
        self::assertSame(0.4, $coincidentEvents[1]->motionZ);
    }

    public function testAirborneKnockbackPreservesVerticalVelocity(): void
    {
        [$world, $factory] = self::twoPlayers();
        $world->enqueue($factory->move(
            session: 'two',
            sequence: 3,
            x: 0.0,
            y: 64.2,
            z: 2.0,
            yaw: 180.0,
            pitch: 0.0,
            mode: MovementMode::JUMPING,
            deltaY: 0.1,
            jumpRequested: true,
        ));
        $world->tick();

        $world->enqueue($factory->attack('one', 2, 0));
        $events = $world->tick()->events;

        self::assertInstanceOf(PlayerKnockedBack::class, $events[1]);
        self::assertEqualsWithDelta(0.2, $events[1]->motionY, 0.000_001);
    }

    public function testSprintAttackStopsAndReconcilesTheAttacker(): void
    {
        [$world, $factory] = self::twoPlayers();
        $world->enqueue($factory->move(
            session: 'one',
            sequence: 1,
            x: 0.2,
            y: 64.0,
            z: 0.0,
            yaw: 0.0,
            pitch: 0.0,
            mode: MovementMode::SPRINTING,
            deltaX: -1.0,
            sprinting: true,
            clientTick: ClientInputTick::fromInt(77),
        ));
        $world->tick();

        $world->enqueue($factory->attack('one', 2, 0));
        $events = $world->tick()->events;

        self::assertCount(4, $events);
        self::assertInstanceOf(PlayerKnockedBack::class, $events[1]);
        self::assertInstanceOf(PlayerMotionChanged::class, $events[2]);
        self::assertEqualsWithDelta(0.04, $events[2]->motionX, 0.000_001);
        self::assertFalse($events[2]->player->sprinting);
        self::assertSame(77, $events[2]->clientTick->low);
    }

    public function testCombatRejectsDisabledSelfUnknownOutOfReachAndRepeatedAttacks(): void
    {
        $factory = new SimulationCommandFactory();
        $disabled = new WorldSimulation(pvp: false);
        $disabled->enqueue($factory->join('one', 'identity-one', 'One', 1));
        $disabled->enqueue($factory->join('two', 'identity-two', 'Two', 2));
        $disabled->tick();
        $disabled->enqueue($factory->attack('one', 2, 0));
        $disabledEvent = $disabled->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $disabledEvent);
        self::assertSame('pvp_disabled', $disabledEvent->reason);

        [$world, $factory] = self::twoPlayers();
        foreach ([[1, 'self_attack'], [99, 'target_unavailable']] as [$target, $reason]) {
            $world->enqueue($factory->attack('one', $target, 0));
            $event = $world->tick()->events[0];
            self::assertInstanceOf(CommandRejected::class, $event);
            self::assertSame($reason, $event->reason);
        }

        $world->enqueue($factory->attack('one', 2, 0));
        $world->tick();
        $world->enqueue($factory->attack('one', 2, 0));
        $cooldown = $world->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $cooldown);
        self::assertSame('damage_cooldown', $cooldown->reason);

        $far = new WorldSimulation();
        $far->enqueue($factory->join('one', 'far-one', 'One', 1));
        $far->enqueue($factory->join('two', 'far-two', 'Two', 2));
        $far->tick();
        $far->enqueue($factory->move('two', 1, 0.0, 64.0, 9.0, 180.0, 0.0, MovementMode::WALKING));
        $far->tick();
        $far->enqueue($factory->attack('one', 2, 0));
        $farEvent = $far->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $farEvent);
        self::assertSame('reach', $farEvent->reason);
    }

    public function testLethalAttackPublishesDamageMotionThenDeath(): void
    {
        [$world, $factory] = self::twoPlayers();
        $died = false;
        for ($hit = 0; $hit < 30; ++$hit) {
            $world->enqueue($factory->attack('one', 2, 0));
            $events = $world->tick()->events;
            if (isset($events[2]) && $events[2] instanceof PlayerDied) {
                self::assertInstanceOf(PlayerDamaged::class, $events[0]);
                self::assertInstanceOf(PlayerKnockedBack::class, $events[1]);
                self::assertSame('identity-one', $events[2]->killer?->identity);
                self::assertInstanceOf(TranslatableMessage::class, $events[2]->deathMessage);
                self::assertSame('death.attack.player', $events[2]->deathMessage->key);
                self::assertSame(['Two', 'One'], $events[2]->deathMessage->parameters);
                $died = true;
                break;
            }
            for ($tick = 0; $tick < 10; ++$tick) {
                $world->tick();
            }
        }
        self::assertTrue($died);
        self::assertFalse($world->snapshot()->players[1]->alive);
    }

    public function testFallDeathMessageUsesTheFinalIncomingDamageThreshold(): void
    {
        $factory = new SimulationCommandFactory();
        foreach ([[1.0, 'death.attack.fall'], [5.0, 'death.fell.accident.generic']] as [$fatalDamage, $expectedKey]) {
            $world = new WorldSimulation();
            $world->enqueue($factory->join('one', 'fall-' . $expectedKey, 'One'));
            $world->tick();
            $world->enqueue($factory->damage('one', 19.0));
            $world->tick();
            for ($tick = 0; $tick < 10; ++$tick) {
                $world->tick();
            }
            $world->enqueue($factory->damage('one', $fatalDamage, DamageCause::Fall));
            $events = $world->tick()->events;

            self::assertInstanceOf(PlayerDied::class, $events[1]);
            self::assertInstanceOf(TranslatableMessage::class, $events[1]->deathMessage);
            self::assertSame($expectedKey, $events[1]->deathMessage->key);
        }
    }

    public function testAttackCommandValidationRejectsWireDomainLeaks(): void
    {
        $factory = new SimulationCommandFactory();
        $attack = $factory->attack('one', 2, 0);
        self::assertInstanceOf(AttackPlayer::class, $attack);

        $this->expectException(\Bedriox\Server\Simulation\CommandValidationException::class);
        $factory->attack('one', 0, 0);
    }

    /** @return array{WorldSimulation, SimulationCommandFactory} */
    private static function twoPlayers(): array
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('one', 'identity-one', 'One', 1));
        $world->enqueue($factory->join('two', 'identity-two', 'Two', 2));
        $world->tick();
        $world->enqueue($factory->move('two', 1, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::WALKING));
        $world->tick();
        $world->enqueue($factory->move('two', 2, 0.0, 64.0, 2.0, 180.0, 0.0, MovementMode::STOPPED));
        $world->tick();

        return [$world, $factory];
    }
}
