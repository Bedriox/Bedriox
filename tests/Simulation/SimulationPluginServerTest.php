<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\SimulationPluginServer;
use Closure;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SimulationPluginServerTest extends TestCase
{
    public function testMutationsStageInsideListenerTransactionsAndCommitInOrder(): void
    {
        $actions = new PluginActionBuffer();
        $messages = [];
        $player = self::player();
        $server = self::server($actions, $player, static function (string $uuid, string $message) use (&$messages): void {
            $messages[] = [$uuid, $message];
        });

        $actions->begin();
        $server->sendMessage($player, 'first');
        $server->sendMessage($player, 'second');
        self::assertSame([], $messages);
        $actions->commit();

        self::assertSame([
            ['identity-one', 'first'],
            ['identity-one', 'second'],
        ], $messages);
    }

    public function testDisabledPluginCannotReadOrMutateThroughAnExistingFacade(): void
    {
        $control = new FacadeRuntimeControl(false);
        $player = self::player();
        $server = self::server(new PluginActionBuffer(), $player, static function (): void {}, $control);

        $this->expectException(PluginException::class);
        $server->onlinePlayers();
    }

    public function testDamageIsBoundedAndStagedLikeOtherAuthoritativeMutations(): void
    {
        $actions = new PluginActionBuffer();
        $damages = [];
        $player = self::player();
        $server = self::server(
            $actions,
            $player,
            static function (): void {},
            damage: static function (string $uuid, float $amount) use (&$damages): void {
                $damages[] = [$uuid, $amount];
            },
        );

        $actions->begin();
        $server->damage($player, 3.5);
        self::assertSame([], $damages);
        $actions->commit();
        self::assertSame([['identity-one', 3.5]], $damages);
    }

    private static function player(): Player
    {
        return new Player(
            'One',
            'identity-one',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }

    private static function server(
        PluginActionBuffer $actions,
        Player $player,
        Closure $sendMessage,
        ?FacadeRuntimeControl $control = null,
        ?Closure $damage = null,
    ): SimulationPluginServer {
        $control ??= new FacadeRuntimeControl(true);

        return new SimulationPluginServer(
            'Example',
            $control,
            $actions,
            static fn(): World => new World('world', new Position(0.0, 64.0, 0.0)),
            static fn(): array => [$player],
            static fn(string $uuid): ?Player => $uuid === $player->uuid ? $player : null,
            static fn(BlockPosition $position): Block => new Block($position, 'minecraft:air'),
            $sendMessage,
            static function (): void {},
            static function (): void {},
            static function (): void {},
            $damage,
        );
    }
}

final class FacadeRuntimeControl implements PluginRuntimeControl
{
    public function __construct(public bool $enabled) {}

    public function isEnabled(string $plugin): bool
    {
        return $this->enabled;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        $this->enabled = false;
    }
}
