<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Player\GameMode;
use Bedriox\Protocol\Packet\Ability;
use Bedriox\Protocol\Packet\CommandPermissionLevel;
use Bedriox\Protocol\Packet\GameType;
use Bedriox\Protocol\Packet\PlayerPermission;
use Bedriox\Server\Runtime\GameModePacketProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameModePacketProjectorTest extends TestCase
{
    /** @return iterable<string, array{GameMode, GameType, list<Ability>}> */
    public static function modes(): iterable
    {
        $interaction = [
            Ability::DoorsAndSwitches,
            Ability::OpenContainers,
            Ability::AttackPlayers,
            Ability::AttackMobs,
        ];

        yield 'survival' => [
            GameMode::SURVIVAL,
            GameType::Survival,
            [Ability::Build, Ability::Mine, ...$interaction],
        ];
        yield 'creative' => [
            GameMode::CREATIVE,
            GameType::Creative,
            [
                Ability::Build,
                Ability::Mine,
                ...$interaction,
                Ability::Invulnerable,
                Ability::MayFly,
                Ability::InstantBuild,
            ],
        ];
        yield 'adventure' => [GameMode::ADVENTURE, GameType::Adventure, $interaction];
        yield 'spectator' => [
            GameMode::SPECTATOR,
            GameType::Spectator,
            [Ability::Invulnerable, Ability::Flying, Ability::MayFly, Ability::NoClip],
        ];
    }

    /** @param list<Ability> $enabled */
    #[DataProvider('modes')]
    public function testProjectsGameTypeAndModeAbilities(
        GameMode $gameMode,
        GameType $gameType,
        array $enabled,
    ): void {
        $projector = new GameModePacketProjector();
        self::assertSame($gameType, $projector->gameType($gameMode));

        $packet = $projector->abilities($gameMode, 42);
        self::assertSame(42, $packet->abilities->uniqueEntityId);
        self::assertSame(PlayerPermission::Member, $packet->abilities->playerPermission);
        self::assertSame(CommandPermissionLevel::Normal, $packet->abilities->commandPermission);
        self::assertCount($gameMode === GameMode::SPECTATOR ? 2 : 1, $packet->abilities->layers);
        $layer = $packet->abilities->layers[0];
        self::assertSame(1, $layer->type);
        self::assertSame(0.05, $layer->flySpeed);
        self::assertSame(1.0, $layer->verticalFlySpeed);
        self::assertSame(0.1, $layer->walkSpeed);
        foreach (Ability::cases() as $ability) {
            self::assertTrue($layer->supports($ability));
            self::assertSame(in_array($ability, $enabled, true), $layer->enabled($ability), $ability->name);
        }
        if ($gameMode === GameMode::SPECTATOR) {
            $spectator = $packet->abilities->layers[1];
            self::assertSame(2, $spectator->type);
            self::assertTrue($spectator->supports(Ability::Flying));
            self::assertTrue($spectator->enabled(Ability::Flying));
        }
    }

    /** @param list<Ability> $enabled */
    #[DataProvider('modes')]
    public function testOperatorAuthorityIsOrthogonalToGameMode(
        GameMode $gameMode,
        GameType $_gameType,
        array $enabled,
    ): void {
        $packet = (new GameModePacketProjector())->abilities($gameMode, -17, true);
        self::assertSame(PlayerPermission::Operator, $packet->abilities->playerPermission);
        self::assertSame(CommandPermissionLevel::Operator, $packet->abilities->commandPermission);
        $layer = $packet->abilities->layers[0];
        self::assertTrue($layer->enabled(Ability::OperatorCommands));
        self::assertTrue($layer->enabled(Ability::Teleport));

        foreach (Ability::cases() as $ability) {
            if ($ability === Ability::OperatorCommands || $ability === Ability::Teleport) {
                continue;
            }
            self::assertSame(in_array($ability, $enabled, true), $layer->enabled($ability), $ability->name);
        }
    }
}
