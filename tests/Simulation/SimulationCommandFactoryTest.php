<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Server\Simulation\CommandValidationException;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\SimulationLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimulationCommandFactoryTest extends TestCase
{
    public function testCreatesBoundedImmutableCommands(): void
    {
        $factory = new SimulationCommandFactory();
        $join = $factory->join('session-1', 'identity-1', 'Veno Player');
        $move = $factory->move(
            'session-1',
            0,
            1.0,
            64.0,
            -1.0,
            180.0,
            -45.0,
            MovementMode::SPRINTING,
            deltaX: 0.6,
            deltaY: 0.2,
            deltaZ: 0.8,
            jumpRequested: true,
            headYaw: 170.0,
            sneaking: true,
            sprinting: false,
        );
        $chat = $factory->chat('session-1', 0, 'Hello, world');
        $emote = $factory->emote('session-1', '00112233-4455-6677-8899-aabbccddeeff');

        self::assertSame('identity-1', $join->identity);
        self::assertSame(MovementMode::SPRINTING, $move->mode);
        self::assertSame(0.6, $move->deltaX);
        self::assertTrue($move->jumpRequested);
        self::assertSame(170.0, $move->headYaw);
        self::assertTrue($move->sneaking);
        self::assertFalse($move->sprinting);
        self::assertSame('Hello, world', $chat->message);
        self::assertSame('00112233-4455-6677-8899-aabbccddeeff', $emote->emoteId);
        self::assertSame(7, $factory->closeContainer('session-1', 7)->windowId);
        self::assertGreaterThan(0, $factory->disconnect('session-1')->estimatedBytes());
    }

    /** @return iterable<string, array{callable(SimulationCommandFactory): mixed}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'empty session' => [static fn(SimulationCommandFactory $factory) => $factory->join('', 'identity', 'Player')];
        yield 'session whitespace' => [static fn(SimulationCommandFactory $factory) => $factory->disconnect("bad\nsession")];
        yield 'identity whitespace' => [static fn(SimulationCommandFactory $factory) => $factory->join('session', 'bad identity', 'Player')];
        yield 'empty display name' => [static fn(SimulationCommandFactory $factory) => $factory->join('session', 'identity', ' ')];
        yield 'long display name' => [static fn(SimulationCommandFactory $factory) => $factory->join('session', 'identity', str_repeat('p', 17))];
        yield 'invalid display UTF-8' => [static fn(SimulationCommandFactory $factory) => $factory->join('session', 'identity', "\xff")];
        yield 'negative movement sequence' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', -1, 0.0, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING)];
        yield 'NaN coordinate' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, NAN, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING)];
        yield 'infinite orientation' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, 0.0, 64.0, 0.0, INF, 0.0, MovementMode::WALKING)];
        yield 'infinite head yaw' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, 0.0, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING, headYaw: INF)];
        yield 'head yaw out of range' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, 0.0, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING, headYaw: 361.0)];
        yield 'extreme horizontal coordinate' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, 30_000_001.0, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING)];
        yield 'extreme vertical coordinate' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, 0.0, 1025.0, 0.0, 0.0, 0.0, MovementMode::WALKING)];
        yield 'pitch out of range' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, 0.0, 64.0, 0.0, 0.0, 91.0, MovementMode::WALKING)];
        yield 'movement delta out of range' => [static fn(SimulationCommandFactory $factory) => $factory->move('session', 1, 0.0, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING, 13.0)];
        yield 'negative chat sequence' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', -1, 'hello')];
        yield 'empty chat' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, '  ')];
        yield 'control chat' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, "hello\rworld")];
        yield 'unicode control chat' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, "hello\u{0085}world")];
        yield 'unicode line separator chat' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, "hello\u{2028}world")];
        yield 'invalid chat UTF-8' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, "\xc3\x28")];
        yield 'chat character limit' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, str_repeat('x', 257))];
        yield 'chat byte limit' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, str_repeat('界', 10))];
        yield 'command-shaped chat' => [static fn(SimulationCommandFactory $factory) => $factory->chat('session', 1, '/stop')];
        yield 'non-canonical emote UUID' => [static fn(SimulationCommandFactory $factory) => $factory->emote('session', '00112233-4455-6677-8899-AABBCCDDEEFF')];
        yield 'container window outside byte range' => [static fn(SimulationCommandFactory $factory) => $factory->closeContainer('session', 256)];
    }

    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidExternalInput(callable $operation): void
    {
        $factory = new SimulationCommandFactory(new SimulationLimits(maximumChatBytes: 16));

        $this->expectException(CommandValidationException::class);
        $operation($factory);
    }
}
