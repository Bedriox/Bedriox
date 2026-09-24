<?php

declare(strict_types=1);

namespace Bedriox\Api;

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;

interface Server
{
    public function world(): World;

    /** @return list<Player> */
    public function onlinePlayers(): array;

    public function player(string $uuid): ?Player;

    public function block(BlockPosition $position): Block;

    public function sendMessage(Player $player, string $message): void;

    public function teleport(Player $player, Position $position, ?float $yaw = null, ?float $pitch = null): void;

    /** Queues bounded server-authoritative damage; PlayerDamageEvent may cancel or modify it. */
    public function damage(Player $player, float $amount): void;

    public function setBlock(BlockPosition $position, string $identifier): void;

    public function setInventorySlot(Player $player, int $slot, ?ItemStack $stack): void;

    public function setGameMode(Player $player, GameMode $gameMode): void;

    public function giveItem(Player $player, ItemStack $stack): void;
}
