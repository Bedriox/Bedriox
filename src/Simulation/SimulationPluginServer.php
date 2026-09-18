<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Server;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use InvalidArgumentException;

/** @internal Callback-backed adapter which keeps public plugin code outside simulation internals. */
final readonly class SimulationPluginServer implements Server
{
    /**
     * @param Closure(): World                                  $world
     * @param Closure(): list<Player>                           $players
     * @param Closure(string): ?Player                          $player
     * @param Closure(BlockPosition): Block                     $block
     * @param Closure(string, string): void                     $sendMessage
     * @param Closure(string, Position): void                   $teleport
     * @param Closure(BlockPosition, string): void              $setBlock
     * @param Closure(string, int, ItemStack|null): void        $setInventorySlot
     */
    public function __construct(
        private string $plugin,
        private PluginRuntimeControl $plugins,
        private PluginActionBuffer $actions,
        private Closure $world,
        private Closure $players,
        private Closure $player,
        private Closure $block,
        private Closure $sendMessage,
        private Closure $teleport,
        private Closure $setBlock,
        private Closure $setInventorySlot,
    ) {}

    public function world(): World
    {
        $this->assertEnabled();

        return ($this->world)();
    }

    public function onlinePlayers(): array
    {
        $this->assertEnabled();

        return ($this->players)();
    }

    public function player(string $uuid): ?Player
    {
        $this->assertEnabled();

        return ($this->player)($uuid);
    }

    public function block(BlockPosition $position): Block
    {
        $this->assertEnabled();

        return ($this->block)($position);
    }

    public function sendMessage(Player $player, string $message): void
    {
        if ($message === '' || strlen($message) > 512 || preg_match('//u', $message) !== 1) {
            throw new InvalidArgumentException('Message must be valid UTF-8 between 1 and 512 bytes.');
        }
        $this->defer(fn() => ($this->sendMessage)($player->uuid, $message));
    }

    public function teleport(Player $player, Position $position): void
    {
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000 || abs($position->z) > 30_000_000
            || $position->y < -64 || $position->y > 319) {
            throw new InvalidArgumentException('Teleport position is outside the supported world boundary.');
        }
        $this->defer(fn() => ($this->teleport)($player->uuid, $position));
    }

    public function setBlock(BlockPosition $position, string $identifier): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Block identifier must be canonical and namespaced.');
        }
        $this->defer(fn() => ($this->setBlock)($position, $identifier));
    }

    public function setInventorySlot(Player $player, int $slot, ?ItemStack $stack): void
    {
        if ($slot < 0 || $slot >= 36) {
            throw new InvalidArgumentException('Inventory slot must be between 0 and 35.');
        }
        $this->defer(fn() => ($this->setInventorySlot)($player->uuid, $slot, $stack));
    }

    private function defer(Closure $action): void
    {
        $this->assertEnabled();
        $guarded = function () use ($action): void {
            $this->assertEnabled();
            $action();
        };
        if ($this->actions->isCapturing()) {
            $this->actions->stage($guarded);
        } else {
            $guarded();
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->plugins->isEnabled($this->plugin)) {
            throw new PluginException("Disabled plugin {$this->plugin} cannot use the server API.");
        }
    }
}
