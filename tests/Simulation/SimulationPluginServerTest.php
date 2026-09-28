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

use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Server;
use Bedriox\Api\World\Position;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\SimulationPluginServer;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SimulationPluginServerTest extends TestCase
{
    public function testPublicServerSurfaceContainsOnlyGlobalDiscoveryCapabilities(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(Server::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        self::assertSame([
            'getOnlinePlayers',
            'getPlayerByName',
            'getPlayerByUuid',
            'getWorldManager',
        ], $methods);
    }

    public function testOnlinePlayersAndUuidLookupUseTheDiscoveryFacade(): void
    {
        $player = self::player();
        $server = self::server($player);

        self::assertSame([$player], $server->getOnlinePlayers());
        self::assertSame($player, $server->getPlayerByUuid('identity-one'));
        self::assertNull($server->getPlayerByUuid('missing'));
    }

    public function testDisabledPluginCannotReadOrMutateThroughAnExistingFacade(): void
    {
        $control = new FacadeRuntimeControl(false);
        $player = self::player();
        $server = self::server($player, $control);

        $this->expectException(PluginException::class);
        $server->getOnlinePlayers();
    }

    public function testPlayerNameLookupIsExactAndCaseInsensitive(): void
    {
        $player = self::player();
        $server = self::server($player);

        self::assertSame($player, $server->getPlayerByName('one'));
        self::assertNull($server->getPlayerByName('on'));
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
        Player $player,
        ?FacadeRuntimeControl $control = null,
    ): SimulationPluginServer {
        $control ??= new FacadeRuntimeControl(true);

        return new SimulationPluginServer(
            'Example',
            $control,
            static fn(): array => [$player],
            static fn(string $uuid): ?Player => $uuid === $player->uuid ? $player : null,
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
