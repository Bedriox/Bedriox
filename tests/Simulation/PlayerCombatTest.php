<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Server\Simulation\Command\AttackPlayer;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class PlayerCombatTest extends TestCase
{
    public function testAttackAppliesAuthoritativeDamageAndKnockback(): void
    {
        [$world, $factory] = self::twoPlayers();

        self::assertTrue($world->enqueue($factory->attack('one', 2, 0)));
        $events = $world->tick()->events;

        self::assertCount(2, $events);
        self::assertInstanceOf(PlayerDamaged::class, $events[0]);
        self::assertSame('two', $events[0]->player->sessionId);
        self::assertSame(1.0, $events[0]->damage);
        self::assertSame(19.0, $events[0]->player->health);
        self::assertInstanceOf(PlayerKnockedBack::class, $events[1]);
        self::assertSame(0.0, $events[1]->motionX);
        self::assertSame(0.4, $events[1]->motionY);
        self::assertSame(0.4, $events[1]->motionZ);
        self::assertSame(19.0, $world->snapshot()->players[1]->health);
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
        for ($hit = 0; $hit < 20; ++$hit) {
            $world->enqueue($factory->attack('one', 2, 0));
            $events = $world->tick()->events;
            if ($hit === 19) {
                self::assertInstanceOf(PlayerDamaged::class, $events[0]);
                self::assertInstanceOf(PlayerKnockedBack::class, $events[1]);
                self::assertInstanceOf(PlayerDied::class, $events[2]);
                break;
            }
            for ($tick = 0; $tick < 10; ++$tick) {
                $world->tick();
            }
        }
        self::assertFalse($world->snapshot()->players[1]->alive);
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

        return [$world, $factory];
    }
}
