<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Player\Player;
use Bedriox\Api\Server;
use Bedriox\Api\World\WorldManager;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;

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
        private Closure $players,
        private Closure $player,
        private ?WorldManager $worldManager = null,
    ) {}

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
