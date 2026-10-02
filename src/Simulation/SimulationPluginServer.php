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

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Player\Player;
use Bedriox\Api\Server;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\Whitelist\Whitelist;
use Bedriox\Api\World\WorldManager;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use InvalidArgumentException;

/** @internal Callback-backed adapter which keeps public plugin code outside simulation internals. */
final readonly class SimulationPluginServer implements Server
{
    /**
     * @param Closure(): list<Player>  $players
     * @param Closure(string): ?Player $player
     */
    public function __construct(
        private string $plugin,
        private PluginRuntimeControl $plugins,
        private PluginActionBuffer $actions,
        private Closure $players,
        private Closure $player,
        private ?WorldManager $worldManager = null,
        private ?Whitelist $whitelist = null,
    ) {}

    public function broadcastMessage(string|TranslatableMessage $message): int
    {
        $this->assertEnabled();
        if (is_string($message)) {
            $characters = preg_match_all('/./us', $message);
            if ($message === '' || strlen($message) > 4096 || !is_int($characters) || $characters > 1024) {
                throw new InvalidArgumentException('Broadcast messages must be valid, non-empty, bounded UTF-8 strings.');
            }
        }
        $players = [];
        foreach (($this->players)() as $player) {
            $players[strtolower($player->uuid)] = $player;
        }
        $send = static function () use ($players, $message): void {
            foreach ($players as $player) {
                $player->sendMessage($message);
            }
        };
        if ($this->actions->isCapturing()) {
            $this->actions->stage($send);
        } else {
            $send();
        }

        return count($players);
    }

    public function getOnlinePlayers(): array
    {
        $this->assertEnabled();

        return ($this->players)();
    }

    public function getWorldManager(): WorldManager
    {
        $this->assertEnabled();

        return $this->worldManager
            ?? throw new \LogicException('The world-management capability is unavailable.');
    }

    public function getWhitelist(): Whitelist
    {
        $this->assertEnabled();
        return $this->whitelist ?? throw new \LogicException('The whitelist capability is unavailable.');
    }

    public function getPlayerByUuid(string $uuid): ?Player
    {
        $this->assertEnabled();

        return ($this->player)($uuid);
    }

    public function getPlayerByName(string $name): ?Player
    {
        $this->assertEnabled();
        foreach (($this->players)() as $player) {
            if (strcasecmp($player->name, $name) === 0) {
                return $player;
            }
        }

        return null;
    }

    private function assertEnabled(): void
    {
        if (!$this->plugins->isEnabled($this->plugin)) {
            throw new PluginException("Disabled plugin {$this->plugin} cannot use the server API.");
        }
    }
}
