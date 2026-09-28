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

namespace Bedriox\Api\Player;

use Bedriox\Api\Inventory\ArmorInventory;
use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\OffHandInventory;
use Bedriox\Api\Inventory\PlayerInventory;
use Bedriox\Api\Inventory\PlayerInventoryActions;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\SetTitlePacket;
use Bedriox\Protocol\Packet\TextPacket;
use Bedriox\Protocol\Packet\ToastRequestPacket;
use Bedriox\Protocol\Packet\TranslatedTextPacket;
use Closure;
use InvalidArgumentException;

/** An immutable snapshot of a connected player. */
final readonly class Player
{
    private Inventory $inventorySnapshot;

    /** @var array<string, ItemStack|null> */
    private array $armorInventorySnapshot;

    /** @param array<mixed> $armorInventory */
    public function __construct(
        public string $name,
        public string $uuid,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public bool $sneaking,
        public bool $sprinting,
        Inventory $inventory,
        public float $health = 20.0,
        public float $maxHealth = 20.0,
        public bool $alive = true,
        public GameMode $gameMode = GameMode::SURVIVAL,
        private ?PlayerConnection $playerConnection = null,
        public Nutrition $nutrition = new Nutrition(20, 20.0, 0.0),
        private ?PlayerActions $playerActions = null,
        array $armorInventory = [],
        private ?ItemStack $offHandItem = null,
        private ?PlayerInventoryActions $inventoryActions = null,
        private ?Closure $maximumStackSize = null,
    ) {
        $this->inventorySnapshot = $inventory;
        $armor = new ArmorInventory(
            $armorInventory,
            PlayerInventoryActions::unavailable(),
        );
        $this->armorInventorySnapshot = $armor->getContents();
    }

    public function connection(): PlayerConnection
    {
        return $this->playerConnection ?? PlayerConnection::disconnected();
    }

    public function isConnected(): bool
    {
        return $this->connection()->isConnected();
    }

    public function getGameMode(): GameMode
    {
        return $this->gameMode;
    }

    public function teleport(Position $position): void
    {
        $position->validate();
        ($this->playerActions ?? PlayerActions::unavailable())->teleport($position);
    }

    public function setGameMode(GameMode $gameMode): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->setGameMode($gameMode);
    }

    public function getInventory(): PlayerInventory
    {
        return new PlayerInventory(
            $this->inventorySnapshot,
            $this->inventoryActions ?? PlayerInventoryActions::unavailable(),
            $this->maximumStackSize,
        );
    }

    public function getArmorInventory(): ArmorInventory
    {
        return new ArmorInventory(
            $this->armorInventorySnapshot,
            $this->inventoryActions ?? PlayerInventoryActions::unavailable(),
        );
    }

    public function getOffHandInventory(): OffHandInventory
    {
        return new OffHandInventory(
            $this->offHandItem,
            $this->inventoryActions ?? PlayerInventoryActions::unavailable(),
        );
    }

    public function damage(float $amount): void
    {
        if (!is_finite($amount) || $amount <= 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Damage must be finite, positive, and bounded.');
        }
        ($this->playerActions ?? PlayerActions::unavailable())->damage($amount);
    }

    /** @internal Rebinds a snapshot without exposing the authoritative action implementation. */
    public function withRuntime(
        PlayerConnection $connection,
        PlayerActions $actions,
        PlayerInventoryActions $inventoryActions,
        Closure $maximumStackSize,
    ): self {
        return new self(
            $this->name,
            $this->uuid,
            $this->position,
            $this->yaw,
            $this->pitch,
            $this->sneaking,
            $this->sprinting,
            $this->inventorySnapshot,
            $this->health,
            $this->maxHealth,
            $this->alive,
            $this->gameMode,
            $connection,
            $this->nutrition,
            $actions,
            $this->armorInventorySnapshot,
            $this->offHandItem,
            $inventoryActions,
            $maximumStackSize,
        );
    }

    /** Requests a cancellable, visible kick. Returns false if the player is offline or a plugin cancels it. */
    public function kick(string $reason = '', ?string $quitMessage = null, ?string $disconnectScreenMessage = null): bool
    {
        return $this->connection()->kick($reason, $quitMessage, $disconnectScreenMessage);
    }

    /** Requests a visibility-scoped arm-swing animation for this player. */
    public function swingArm(): bool
    {
        return $this->connection()->swingArm();
    }

    /** Opens a real or virtual authoritative inventory through its public handle. */
    public function openInventory(Container $container): bool
    {
        return $container->open($this);
    }

    /** Closes this inventory for the player through the same authoritative lifecycle path. */
    public function closeInventory(Container $container): bool
    {
        return $container->close($this);
    }

    public function sendMessage(string|TranslatableMessage $message): bool
    {
        return $this->connection()->sendPacket($message instanceof TranslatableMessage
            ? new TranslatedTextPacket($message->key, $message->parameters)
            : TextPacket::raw($message));
    }

    public function sendPopup(string $message): bool
    {
        return $this->connection()->sendPacket(TextPacket::popup($message));
    }

    public function sendJukeboxPopup(string|TranslatableMessage $message): bool
    {
        return $this->connection()->sendPacket($message instanceof TranslatableMessage
            ? TextPacket::jukeboxPopup($message->key, $message->parameters)
            : TextPacket::jukeboxPopup($message));
    }

    public function sendTip(string $message): bool
    {
        return $this->connection()->sendPacket(TextPacket::tip($message));
    }

    public function sendTitle(string $title, string $subtitle = '', ?TitleTimes $times = null): bool
    {
        $connection = $this->connection();
        if ($times !== null && !$connection->sendPacket(SetTitlePacket::times(
            $times->fadeIn,
            $times->stay,
            $times->fadeOut,
        ))) {
            return false;
        }
        if ($subtitle !== '' && !$connection->sendPacket(SetTitlePacket::subtitle($subtitle))) {
            return false;
        }

        return $connection->sendPacket(SetTitlePacket::title($title));
    }

    public function sendSubTitle(string $subtitle): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::subtitle($subtitle));
    }

    public function sendActionBar(string $message): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::actionBar($message));
    }

    public function setTitleTimes(TitleTimes $times): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::times($times->fadeIn, $times->stay, $times->fadeOut));
    }

    public function clearTitle(): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::clear());
    }

    public function resetTitles(): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::reset());
    }

    public function sendToast(string $title, string $body): bool
    {
        return $this->connection()->sendPacket(new ToastRequestPacket($title, $body));
    }
}
