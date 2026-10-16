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

use Bedriox\Api\Effect\EffectActions;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectManager;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\Skin;
use Bedriox\Api\Entity\Value\EmoteId;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Event\Player\PlayerKickCause;
use Bedriox\Api\Inventory\ArmorInventory;
use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\OffHandInventory;
use Bedriox\Api\Inventory\PlayerInventory;
use Bedriox\Api\Inventory\PlayerInventoryActions;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
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
        /** @var array<string, EffectInstance> $effects */
        private array $effects = [],
        private ?EffectActions $effectActions = null,
        private ExperienceSnapshot $experience = new ExperienceSnapshot(0),
        private ?Closure $setExperience = null,
        private ?Skin $skin = null,
        private Motion $motion = new Motion(),
        private bool $sleeping = false,
        private ?BlockPosition $bedPosition = null,
    ) {
        $this->inventorySnapshot = $inventory;
        $armor = new ArmorInventory(
            $armorInventory,
            PlayerInventoryActions::unavailable(),
        );
        $this->armorInventorySnapshot = $armor->getContents();
        foreach ($this->effects as $identifier => $effect) {
            if ($identifier !== $effect->type->value) {
                throw new InvalidArgumentException('Player effects must be indexed by canonical effect identifier.');
            }
        }
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

    public function getDimension(): DimensionType
    {
        return $this->getWorld()->getDimensionType();
    }

    public function getWorld(): World
    {
        return $this->position->world
            ?? throw new \LogicException('This player snapshot does not contain a resolved world.');
    }

    public function getSkin(): Skin
    {
        return $this->skin ?? throw new \LogicException('This player snapshot does not contain a verified skin.');
    }

    public function getMotion(): Motion
    {
        return $this->motion;
    }

    public function isSleeping(): bool
    {
        return $this->sleeping;
    }

    public function getBedPosition(): ?BlockPosition
    {
        return $this->bedPosition;
    }

    public function setMotion(Motion $motion): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->setMotion($motion);
    }

    public function playEmote(EmoteId $emote): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->playEmote($emote);
    }

    public function setRotation(float $yaw, float $pitch): void
    {
        if (!is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Player rotation must be finite and bounded.');
        }
        ($this->playerActions ?? PlayerActions::unavailable())->setRotation($yaw, $pitch);
    }

    public function setNameTag(string $nameTag): void
    {
        if (strlen($nameTag) > 256 || preg_match('//u', $nameTag) !== 1) {
            throw new InvalidArgumentException('Player name tag must be valid UTF-8 and bounded.');
        }
        ($this->playerActions ?? PlayerActions::unavailable())->setNameTag($nameTag);
    }

    public function setNameTagVisible(bool $visible): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->setNameTagVisible($visible);
    }

    public function setImmobile(bool $immobile): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->setImmobile($immobile);
    }

    public function setInvisible(bool $invisible): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->setInvisible($invisible);
    }

    public function setScale(float $scale): void
    {
        if (!is_finite($scale) || $scale < 0.01 || $scale > 16.0) {
            throw new InvalidArgumentException('Player scale must be finite and between 0.01 and 16.');
        }
        ($this->playerActions ?? PlayerActions::unavailable())->setScale($scale);
    }

    public function setGravityEnabled(bool $enabled): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->setGravityEnabled($enabled);
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

    public function getEffects(): EffectManager
    {
        return new EffectManager($this->effects, $this->effectActions ?? EffectActions::unavailable());
    }

    public function getExperience(): ExperienceManager
    {
        return $this->setExperience === null
            ? ExperienceManager::unavailable($this->experience)
            : new ExperienceManager($this->experience, $this->setExperience);
    }

    public function damage(float $amount): void
    {
        if (!is_finite($amount) || $amount <= 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Damage must be finite, positive, and bounded.');
        }
        ($this->playerActions ?? PlayerActions::unavailable())->damage($amount);
    }

    public function getVehicle(): ?Entity
    {
        return ($this->playerActions ?? PlayerActions::unavailable())->vehicle();
    }

    public function isRiding(): bool
    {
        return $this->getVehicle() !== null;
    }

    public function mount(Entity $vehicle, MountSeat $seat = MountSeat::DRIVER): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->mount($vehicle, $seat);
    }

    public function dismount(): void
    {
        ($this->playerActions ?? PlayerActions::unavailable())->dismount();
    }

    /** @internal Rebinds a snapshot without exposing the authoritative action implementation. */
    public function withRuntime(
        PlayerConnection $connection,
        PlayerActions $actions,
        PlayerInventoryActions $inventoryActions,
        Closure $maximumStackSize,
        ?EffectActions $effectActions = null,
        ?Closure $setExperience = null,
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
            $this->effects,
            $effectActions,
            $this->experience,
            $setExperience,
            $this->skin,
            $this->motion,
            $this->sleeping,
            $this->bedPosition,
        );
    }

    /** @internal Attaches the verified login skin without changing the player session binding. */
    public function withSkin(Skin $skin): self
    {
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
            $this->playerConnection,
            $this->nutrition,
            $this->playerActions,
            $this->armorInventorySnapshot,
            $this->offHandItem,
            $this->inventoryActions,
            $this->maximumStackSize,
            $this->effects,
            $this->effectActions,
            $this->experience,
            $this->setExperience,
            $skin,
            $this->motion,
            $this->sleeping,
            $this->bedPosition,
        );
    }

    /** Requests a cancellable, visible kick. Returns false if the player is offline or a plugin cancels it. */
    public function kick(
        string $reason = '',
        ?string $quitMessage = null,
        ?string $disconnectScreenMessage = null,
        PlayerKickCause $cause = PlayerKickCause::PLUGIN,
        ?string $actor = null,
    ): bool {
        return $this->connection()->kick($reason, $quitMessage, $disconnectScreenMessage, $cause, $actor);
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
