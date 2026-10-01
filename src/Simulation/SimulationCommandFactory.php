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

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\Value\MountReason;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Player\ExperienceChangeCause;
use Bedriox\Api\Player\ExperienceSnapshot;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\World\Particle\Particle;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Simulation\Command\AcknowledgeRespawn;
use Bedriox\Server\Simulation\Command\AddPlayerEffect;
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\AttackPlayer;
use Bedriox\Server\Simulation\Command\BreakBlock;
use Bedriox\Server\Simulation\Command\ChangeGameMode;
use Bedriox\Server\Simulation\Command\ClearPlayerEffects;
use Bedriox\Server\Simulation\Command\CloseContainer;
use Bedriox\Server\Simulation\Command\CloseCraftingGrid;
use Bedriox\Server\Simulation\Command\CraftingRequest;
use Bedriox\Server\Simulation\Command\DamageEntity;
use Bedriox\Server\Simulation\Command\DamagePlayer;
use Bedriox\Server\Simulation\Command\DisconnectPlayer;
use Bedriox\Server\Simulation\Command\DismountPlayer;
use Bedriox\Server\Simulation\Command\DropItem;
use Bedriox\Server\Simulation\Command\GiveItem;
use Bedriox\Server\Simulation\Command\InteractEntity;
use Bedriox\Server\Simulation\Command\JoinPlayer;
use Bedriox\Server\Simulation\Command\MountPlayer;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\PerformEmote;
use Bedriox\Server\Simulation\Command\PlaceBlock;
use Bedriox\Server\Simulation\Command\ReleaseItem;
use Bedriox\Server\Simulation\Command\RemovePlayerEffect;
use Bedriox\Server\Simulation\Command\RemovePluginInventoryStack;
use Bedriox\Server\Simulation\Command\RespawnPlayer;
use Bedriox\Server\Simulation\Command\SelectHotbarSlot;
use Bedriox\Server\Simulation\Command\SendChat;
use Bedriox\Server\Simulation\Command\SendPluginMessage;
use Bedriox\Server\Simulation\Command\SetPlayerExperience;
use Bedriox\Server\Simulation\Command\SetPluginArmorContents;
use Bedriox\Server\Simulation\Command\SetPluginBlock;
use Bedriox\Server\Simulation\Command\SetPluginEquipmentSlot;
use Bedriox\Server\Simulation\Command\SetPluginInventoryContents;
use Bedriox\Server\Simulation\Command\SetPluginInventorySlot;
use Bedriox\Server\Simulation\Command\SpawnPluginParticle;
use Bedriox\Server\Simulation\Command\SwingArm;
use Bedriox\Server\Simulation\Command\SyncInventory;
use Bedriox\Server\Simulation\Command\SyncInventorySlots;
use Bedriox\Server\Simulation\Command\TeleportPlayer;
use Bedriox\Server\Simulation\Command\UseItem;
use Bedriox\Server\Simulation\Command\WorkstationRequest;
use Bedriox\Server\World\BlockPosition;

final readonly class SimulationCommandFactory
{
    public function __construct(private SimulationLimits $limits = new SimulationLimits()) {}

    public function join(
        string $session,
        string $identity,
        string $displayName,
        ?int $runtimeActorId = null,
        ?PlayerBootstrap $bootstrap = null,
        bool $loginApproved = false,
    ): JoinPlayer {
        $this->assertOpaqueId($session, 128, 'session');
        $this->assertOpaqueId($identity, 128, 'identity');
        $this->assertUtf8Text($displayName, 16, 64, 'display name');
        if ($runtimeActorId !== null && $runtimeActorId < 1) {
            throw new CommandValidationException('Runtime actor ID must be positive.');
        }

        if ($bootstrap !== null && ($bootstrap->identity->uuid !== $identity
            || $bootstrap->identity->displayName !== $displayName)) {
            throw new CommandValidationException('Player bootstrap identity does not match the join command.');
        }

        return new JoinPlayer($session, $identity, $displayName, $runtimeActorId, $bootstrap, $loginApproved);
    }

    public function move(
        string $session,
        int $sequence,
        float $x,
        float $y,
        float $z,
        float $yaw,
        float $pitch,
        MovementMode $mode,
        float $deltaX = 0.0,
        float $deltaY = 0.0,
        float $deltaZ = 0.0,
        bool $jumpRequested = false,
        ?float $headYaw = null,
        ?bool $sneaking = null,
        ?bool $sprinting = null,
        ?ClientInputTick $clientTick = null,
        bool $flying = false,
        bool $verticalCollision = false,
        float $moveX = 0.0,
        float $moveZ = 0.0,
        ?float $vehiclePitch = null,
        ?float $vehicleYaw = null,
        ?float $vehicleControlYaw = null,
        ?int $predictedVehicleActorId = null,
    ): MovePlayer {
        $this->assertOpaqueId($session, 128, 'session');
        if ($sequence < 0) {
            throw new CommandValidationException('Movement sequence cannot be negative.');
        }
        foreach (['x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw, 'pitch' => $pitch, 'head yaw' => $headYaw ?? $yaw, 'delta x' => $deltaX, 'delta y' => $deltaY, 'delta z' => $deltaZ, 'move x' => $moveX, 'move z' => $moveZ, 'vehicle pitch' => $vehiclePitch ?? 0.0, 'vehicle yaw' => $vehicleYaw ?? 0.0, 'vehicle control yaw' => $vehicleControlYaw ?? 0.0] as $name => $value) {
            if (!is_finite($value)) {
                throw new CommandValidationException(sprintf('%s must be finite.', $name));
            }
        }
        if (abs($x) > $this->limits->maximumCoordinate || abs($z) > $this->limits->maximumCoordinate) {
            throw new CommandValidationException('Horizontal position exceeds the world boundary.');
        }
        if ($y < -64.0 || $y > 1024.0) {
            throw new CommandValidationException('Vertical position exceeds the world boundary.');
        }
        if ($yaw < -360.0 || $yaw > 360.0 || ($headYaw !== null && ($headYaw < -360.0 || $headYaw > 360.0)) || $pitch < -90.0 || $pitch > 90.0) {
            throw new CommandValidationException('Orientation exceeds its accepted range.');
        }
        if (hypot(hypot($deltaX, $deltaZ), $deltaY) > $this->limits->maximumMovementPerTick) {
            throw new CommandValidationException('Predicted movement delta exceeds its per-tick range.');
        }
        if (hypot($moveX, $moveZ) > 1.5) {
            throw new CommandValidationException('Movement input exceeds its accepted range.');
        }
        if (($vehiclePitch === null) !== ($vehicleYaw === null)
            || ($vehicleYaw === null) !== ($vehicleControlYaw === null)
            || ($vehicleControlYaw === null) !== ($predictedVehicleActorId === null)) {
            throw new CommandValidationException('Predicted vehicle input must be complete.');
        }
        if ($vehiclePitch !== null && (abs($vehiclePitch) > 360.0
            || $vehicleYaw < 0.0 || $vehicleYaw >= 360.0
            || $vehicleControlYaw < 0.0 || $vehicleControlYaw >= 360.0)) {
            throw new CommandValidationException('Predicted vehicle rotation exceeds its accepted range.');
        }
        if ($predictedVehicleActorId !== null && $predictedVehicleActorId < 1) {
            throw new CommandValidationException('Predicted vehicle actor identifier must be positive.');
        }

        return new MovePlayer(
            $session,
            $sequence,
            new Position($x, $y, $z),
            $yaw,
            $pitch,
            $mode,
            $deltaX,
            $deltaY,
            $deltaZ,
            $jumpRequested,
            $headYaw,
            $sneaking,
            $sprinting,
            $clientTick ?? ClientInputTick::fromInt($sequence),
            $flying,
            $verticalCollision,
            $moveX,
            $moveZ,
            $vehiclePitch,
            $vehicleYaw,
            $vehicleControlYaw,
            $predictedVehicleActorId,
        );
    }

    public function mountPlayer(
        string $session,
        int $vehicleRuntimeId,
        string $vehicleUniqueId,
        MountSeat $seat = MountSeat::DRIVER,
        MountReason $reason = MountReason::INTERACTION,
    ): MountPlayer {
        $this->assertOpaqueId($session, 128, 'session');
        if ($vehicleRuntimeId < 1) {
            throw new CommandValidationException('Vehicle runtime actor identifier must be positive.');
        }
        $this->assertOpaqueId($vehicleUniqueId, 128, 'vehicle unique identifier');

        return new MountPlayer($session, $vehicleRuntimeId, $vehicleUniqueId, $seat, $reason);
    }

    public function dismountPlayer(string $session, MountReason $reason = MountReason::DISMOUNT_INPUT): DismountPlayer
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new DismountPlayer($session, $reason);
    }

    public function chat(string $session, int $sequence, string $message): SendChat
    {
        $this->assertOpaqueId($session, 128, 'session');
        if ($sequence < 0) {
            throw new CommandValidationException('Chat sequence cannot be negative.');
        }
        $this->assertUtf8Text(
            $message,
            $this->limits->maximumChatCharacters,
            $this->limits->maximumChatBytes,
            'chat message',
        );
        if (str_starts_with($message, '/')) {
            throw new CommandValidationException('Commands must use the command input boundary.');
        }

        return new SendChat($session, $sequence, $message);
    }

    public function pluginMessage(string $session, string $message): SendPluginMessage
    {
        $this->assertOpaqueId($session, 128, 'session');
        $this->assertUtf8Text($message, $this->limits->maximumChatCharacters, $this->limits->maximumChatBytes, 'message');

        return new SendPluginMessage($session, $message);
    }

    public function addPlayerEffect(
        string $session,
        EffectInstance $effect,
        EffectCause $cause,
    ): AddPlayerEffect {
        $this->assertOpaqueId($session, 128, 'session');

        return new AddPlayerEffect($session, $effect, $cause);
    }

    public function removePlayerEffect(
        string $session,
        EffectType $type,
        EffectCause $cause,
    ): RemovePlayerEffect {
        $this->assertOpaqueId($session, 128, 'session');

        return new RemovePlayerEffect($session, $type, $cause);
    }

    public function clearPlayerEffects(string $session, EffectCause $cause): ClearPlayerEffects
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new ClearPlayerEffects($session, $cause);
    }

    public function setPlayerExperience(
        string $session,
        int $totalPoints,
        ExperienceChangeCause $cause,
    ): SetPlayerExperience {
        $this->assertOpaqueId($session, 128, 'session');
        new ExperienceSnapshot($totalPoints);

        return new SetPlayerExperience($session, $totalPoints, $cause);
    }

    public function teleport(
        string $session,
        float $x,
        float $y,
        float $z,
        ?float $yaw = null,
        ?float $pitch = null,
    ): TeleportPlayer {
        $this->assertOpaqueId($session, 128, 'session');
        foreach (['x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw ?? 0.0, 'pitch' => $pitch ?? 0.0] as $name => $value) {
            if (!is_finite($value)) {
                throw new CommandValidationException("Teleport {$name} must be finite.");
            }
        }
        if (abs($x) > $this->limits->maximumCoordinate || abs($z) > $this->limits->maximumCoordinate
            || $y < -64.0 || $y > 319.0) {
            throw new CommandValidationException('Teleport position exceeds the world boundary.');
        }
        if (($yaw !== null && ($yaw < -360.0 || $yaw > 360.0))
            || ($pitch !== null && ($pitch < -90.0 || $pitch > 90.0))) {
            throw new CommandValidationException('Teleport orientation exceeds its accepted range.');
        }

        return new TeleportPlayer($session, new Position($x, $y, $z), $yaw, $pitch);
    }

    public function pluginBlock(string $plugin, BlockPosition $position, string $identifier): SetPluginBlock
    {
        $this->assertOpaqueId($plugin, 64, 'plugin');
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
            || abs($position->x) > $this->limits->maximumCoordinate
            || abs($position->z) > $this->limits->maximumCoordinate
            || $position->y < -64 || $position->y > 319) {
            throw new CommandValidationException('Plugin block change is invalid.');
        }

        return new SetPluginBlock($plugin, $position, $identifier);
    }

    /** @param list<string>|null $targetIdentities */
    public function pluginParticle(
        string $plugin,
        Position $position,
        Particle $particle,
        ?array $targetIdentities,
    ): SpawnPluginParticle {
        $this->assertOpaqueId($plugin, 64, 'plugin');
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > $this->limits->maximumCoordinate
            || abs($position->z) > $this->limits->maximumCoordinate
            || $position->y < -64.0 || $position->y > 319.0
            || ($targetIdentities !== null && count($targetIdentities) > 128)) {
            throw new CommandValidationException('Plugin particle request is invalid.');
        }
        foreach ($targetIdentities ?? [] as $identity) {
            $this->assertOpaqueId($identity, 128, 'particle target identity');
        }

        return new SpawnPluginParticle($plugin, $position, $particle, $targetIdentities);
    }

    public function pluginInventorySlot(
        string $session,
        int $slot,
        ?InventoryStack $stack,
    ): SetPluginInventorySlot {
        $this->assertOpaqueId($session, 128, 'session');
        if ($slot < 0 || $slot >= 36) {
            throw new CommandValidationException('Plugin inventory slot is invalid.');
        }

        return new SetPluginInventorySlot($session, $slot, $stack);
    }

    /** @param array<int, InventoryStack|null> $contents */
    public function pluginInventoryContents(string $session, array $contents): SetPluginInventoryContents
    {
        $this->assertOpaqueId($session, 128, 'session');
        if (count($contents) !== PlayerInventory::SLOT_COUNT || !array_is_list($contents)) {
            throw new CommandValidationException('Plugin inventory contents must contain exactly 36 slots.');
        }
        return new SetPluginInventoryContents($session, $contents);
    }

    /** @param array<int, InventoryStack|null> $contents */
    public function pluginArmorContents(string $session, array $contents): SetPluginArmorContents
    {
        $this->assertOpaqueId($session, 128, 'session');
        if (count($contents) !== PlayerInventory::ARMOR_SLOT_COUNT || !array_is_list($contents)) {
            throw new CommandValidationException('Plugin armor contents must contain exactly four slots.');
        }

        return new SetPluginArmorContents($session, $contents);
    }

    public function removePluginInventoryStack(string $session, InventoryStack $stack): RemovePluginInventoryStack
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new RemovePluginInventoryStack($session, $stack);
    }

    public function pluginEquipmentSlot(
        string $session,
        EquipmentSlot $slot,
        ?InventoryStack $stack,
    ): SetPluginEquipmentSlot {
        $this->assertOpaqueId($session, 128, 'session');
        if ($slot === EquipmentSlot::MAIN_HAND) {
            throw new CommandValidationException('Main-hand changes must target the selected main inventory slot.');
        }

        return new SetPluginEquipmentSlot($session, $slot, $stack);
    }

    public function emote(string $session, string $emoteId): PerformEmote
    {
        $this->assertOpaqueId($session, 128, 'session');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $emoteId) !== 1) {
            throw new CommandValidationException('Emote ID must be a canonical lowercase UUID.');
        }

        return new PerformEmote($session, $emoteId);
    }

    public function swingArm(string $session, ArmSwingSource $source): SwingArm
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new SwingArm($session, $source);
    }

    public function disconnect(string $session): DisconnectPlayer
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new DisconnectPlayer($session);
    }

    public function damage(string $session, float $amount, DamageCause $cause = DamageCause::Plugin): DamagePlayer
    {
        $this->assertOpaqueId($session, 128, 'session');
        if (!is_finite($amount) || $amount <= 0.0 || $amount > 1_000_000.0) {
            throw new CommandValidationException('Damage must be finite, positive, and bounded.');
        }

        return new DamagePlayer($session, $amount, $cause);
    }

    public function damageEntity(
        string $source,
        int $runtimeId,
        string $uniqueId,
        float $amount,
        EntityDamageCause $cause = EntityDamageCause::PLUGIN,
    ): DamageEntity {
        $this->assertOpaqueId($source, 128, 'entity damage source');
        if ($runtimeId < 1 || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uniqueId) !== 1
            || !is_finite($amount) || $amount <= 0.0 || $amount > 1_000_000.0) {
            throw new CommandValidationException('Entity damage request is invalid or outside its supported bounds.');
        }

        return new DamageEntity($source, $runtimeId, $uniqueId, $amount, $cause);
    }

    public function attack(string $session, int $targetRuntimeActorId, int $hotbarSlot): AttackPlayer
    {
        $this->assertOpaqueId($session, 128, 'session');
        if ($targetRuntimeActorId < 1 || $hotbarSlot < 0 || $hotbarSlot > 8) {
            throw new CommandValidationException('Player attack intent is invalid.');
        }

        return new AttackPlayer($session, $targetRuntimeActorId, $hotbarSlot);
    }

    public function interactEntity(
        string $session,
        int $targetRuntimeActorId,
        int $hotbarSlot,
        EntityInteractionType $interaction,
    ): InteractEntity {
        $this->assertOpaqueId($session, 128, 'session');
        if ($targetRuntimeActorId < 1 || $hotbarSlot < 0 || $hotbarSlot > 8) {
            throw new CommandValidationException('Entity interaction intent is invalid.');
        }

        return new InteractEntity($session, $targetRuntimeActorId, $hotbarSlot, $interaction);
    }

    public function changeGameMode(string $session, GameMode $gameMode): ChangeGameMode
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new ChangeGameMode($session, $gameMode);
    }

    public function giveItem(
        string $session,
        string $identifier,
        int $amount,
        int $damage = 0,
        ?\Bedriox\Api\Inventory\ItemNbt $nbt = null,
        int $auxValue = 0,
    ): GiveItem {
        $this->assertOpaqueId($session, 128, 'session');
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $identifier) !== 1
            || $amount < 1 || $amount > 32_767 || $damage < 0 || $damage > 65_535
            || $auxValue < 0 || $auxValue > 32_767) {
            throw new CommandValidationException('Given item is invalid or outside its bounded amount.');
        }

        return new GiveItem($session, $identifier, $amount, $damage, $nbt, $auxValue);
    }

    public function dropItem(
        string $session,
        int $requestId,
        InventorySlotReference $source,
        int $count,
        InventoryResponseMode $responseMode,
        ?InventoryStack $expectedStack = null,
    ): DropItem {
        $this->assertOpaqueId($session, 128, 'session');
        if ($requestId < -0x80000000 || $requestId > 0x7fffffff
            || !in_array($source->container, [
                InventoryContainer::Main,
                InventoryContainer::Cursor,
                InventoryContainer::Armor,
                InventoryContainer::Offhand,
                InventoryContainer::CraftingInput,
                InventoryContainer::OpenedContainer,
            ], true)
            || ($source->container === InventoryContainer::Main
                && ($source->slot < 0 || $source->slot >= PlayerInventory::SLOT_COUNT))
            || ($source->container === InventoryContainer::Cursor && $source->slot !== 0)
            || ($source->container === InventoryContainer::Armor
                && ($source->slot < 0 || $source->slot >= PlayerInventory::ARMOR_SLOT_COUNT))
            || ($source->container === InventoryContainer::Offhand && $source->slot !== 0)
            || ($source->container === InventoryContainer::CraftingInput
                && ($source->slot < 0 || $source->slot >= 9))
            || ($source->container === InventoryContainer::OpenedContainer
                && ($source->slot < 0 || $source->slot > 0xff))
            || $source->expectedStackNetworkId < -0x80000000
            || $source->expectedStackNetworkId > 0x7fffffff
            || $count < 1 || $count > 64
            || ($expectedStack !== null && $expectedStack->count !== ($source->expectedCount ?? $expectedStack->count))) {
            throw new CommandValidationException('Item drop intent is invalid.');
        }

        return new DropItem($session, $requestId, $source, $count, $responseMode, $expectedStack);
    }

    public function syncInventory(string $session): SyncInventory
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new SyncInventory($session);
    }

    public function closeCraftingGrid(string $session): CloseCraftingGrid
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new CloseCraftingGrid($session);
    }

    public function closeContainer(string $session, int $windowId): CloseContainer
    {
        $this->assertOpaqueId($session, 128, 'session');
        if ($windowId < 0 || $windowId > 0xff) {
            throw new CommandValidationException('Container window ID must fit one byte.');
        }

        return new CloseContainer($session, $windowId);
    }

    /** @param list<mixed> $slots */
    public function syncInventorySlots(string $session, array $slots): SyncInventorySlots
    {
        $this->assertOpaqueId($session, 128, 'session');
        if ($slots === [] || count($slots) > PlayerInventory::SLOT_COUNT
            + PlayerInventory::ARMOR_SLOT_COUNT + 11 + 256) {
            throw new CommandValidationException('Inventory slot sync is outside its bounded range.');
        }
        $seen = [];
        foreach ($slots as $slot) {
            if (!$slot instanceof InventorySlotReference
                || $slot->container === InventoryContainer::CreatedOutput
                || ($slot->container === InventoryContainer::Main
                    && ($slot->slot < 0 || $slot->slot >= PlayerInventory::SLOT_COUNT))
                || ($slot->container === InventoryContainer::Armor
                    && ($slot->slot < 0 || $slot->slot >= PlayerInventory::ARMOR_SLOT_COUNT))
                || ($slot->container === InventoryContainer::Cursor && $slot->slot !== 0)
                || ($slot->container === InventoryContainer::Offhand && $slot->slot !== 0)
                || ($slot->container === InventoryContainer::CraftingInput
                    && ($slot->slot < 0 || $slot->slot >= 9))
                || ($slot->container === InventoryContainer::OpenedContainer
                    && ($slot->slot < 0 || $slot->slot > 0xff))
                || isset($seen[$slot->key()])) {
                throw new CommandValidationException('Inventory slot sync contains an invalid slot.');
            }
            $seen[$slot->key()] = true;
        }

        return new SyncInventorySlots($session, $slots);
    }

    public function respawn(string $session): RespawnPlayer
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new RespawnPlayer($session);
    }

    public function acknowledgeRespawn(string $session): AcknowledgeRespawn
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new AcknowledgeRespawn($session);
    }

    public function breakBlock(
        string $session,
        int $sequence,
        BlockBreakAction $action,
        ?BlockPosition $position,
        int $face,
    ): BreakBlock {
        $this->assertOpaqueId($session, 128, 'session');
        if ($sequence < 1 || $face < 0 || $face > 5 || ($action !== BlockBreakAction::Abort && $position === null)) {
            throw new CommandValidationException('Block-break intent is invalid.');
        }

        return new BreakBlock($session, $sequence, $action, $position, $face);
    }

    public function placeBlock(
        string $session,
        int $sequence,
        BlockPosition $clickedPosition,
        int $face,
        int $hotbarSlot,
        int $hand,
        float $clickX,
        float $clickY,
        float $clickZ,
    ): PlaceBlock {
        $this->assertOpaqueId($session, 128, 'session');
        if ($sequence < 1 || $face < 0 || $face > 5 || $hotbarSlot < 0 || $hotbarSlot > 8 || $hand !== 0) {
            throw new CommandValidationException('Block-placement intent is invalid.');
        }
        foreach ([$clickX, $clickY, $clickZ] as $component) {
            if (!is_finite($component) || $component < 0.0 || $component > 1.0) {
                throw new CommandValidationException('Block-placement click position must be within the target block.');
            }
        }

        return new PlaceBlock(
            $session,
            $sequence,
            $clickedPosition,
            $face,
            $hotbarSlot,
            $hand,
            $clickX,
            $clickY,
            $clickZ,
        );
    }

    public function selectHotbarSlot(string $session, int $hotbarSlot): SelectHotbarSlot
    {
        $this->assertOpaqueId($session, 128, 'session');
        if ($hotbarSlot < 0 || $hotbarSlot > 8) {
            throw new CommandValidationException('Selected hotbar slot is outside its supported range.');
        }

        return new SelectHotbarSlot($session, $hotbarSlot);
    }

    public function useItem(string $session, int $hotbarSlot): UseItem
    {
        $this->assertOpaqueId($session, 128, 'session');
        if ($hotbarSlot < 0 || $hotbarSlot > 8) {
            throw new CommandValidationException('Item-use hotbar slot is outside its supported range.');
        }

        return new UseItem($session, $hotbarSlot);
    }

    public function releaseItem(string $session, int $hotbarSlot): ReleaseItem
    {
        $this->assertOpaqueId($session, 128, 'session');
        if ($hotbarSlot < 0 || $hotbarSlot > 8) {
            throw new CommandValidationException('Item-release hotbar slot is outside its supported range.');
        }

        return new ReleaseItem($session, $hotbarSlot);
    }

    /** @param list<mixed> $actions */
    public function inventoryStackRequest(
        string $session,
        int $requestId,
        array $actions,
        ?string $rejectionReason = null,
        InventoryResponseMode $responseMode = InventoryResponseMode::ItemStackResponse,
        ?InventoryStack $authoritativeCreativeStack = null,
        ?CraftingRequest $crafting = null,
        ?WorkstationRequest $workstation = null,
    ): ApplyInventoryStackRequest {
        $this->assertOpaqueId($session, 128, 'session');
        if ($actions === [] && $rejectionReason === null
            && $authoritativeCreativeStack === null && $crafting === null && $workstation === null) {
            $rejectionReason = 'empty_actions';
        }
        if ($requestId < -0x80000000 || $requestId > 0x7fffffff || count($actions) > 100
            || ($rejectionReason !== null && ($rejectionReason === '' || strlen($rejectionReason) > 64))) {
            throw new CommandValidationException('Inventory stack request is outside its bounded range.');
        }
        $validatedActions = [];
        foreach ($actions as $action) {
            if (!$action instanceof InventoryStackRequestAction
                || ($action->source->container === InventoryContainer::Main
                    && ($action->source->slot < 0 || $action->source->slot >= PlayerInventory::SLOT_COUNT))
                || ($action->destination->container === InventoryContainer::Main
                    && ($action->destination->slot < 0 || $action->destination->slot >= PlayerInventory::SLOT_COUNT))
                || ($action->source->container === InventoryContainer::Cursor && $action->source->slot !== 0)
                || ($action->destination->container === InventoryContainer::Cursor && $action->destination->slot !== 0)
                || ($action->source->container === InventoryContainer::Armor
                    && ($action->source->slot < 0 || $action->source->slot >= PlayerInventory::ARMOR_SLOT_COUNT))
                || ($action->destination->container === InventoryContainer::Armor
                    && ($action->destination->slot < 0
                        || $action->destination->slot >= PlayerInventory::ARMOR_SLOT_COUNT))
                || ($action->source->container === InventoryContainer::Offhand && $action->source->slot !== 0)
                || ($action->destination->container === InventoryContainer::Offhand
                    && $action->destination->slot !== 0)
                || ($action->source->container === InventoryContainer::CraftingInput
                    && ($action->source->slot < 0 || $action->source->slot >= 9))
                || ($action->destination->container === InventoryContainer::CraftingInput
                    && ($action->destination->slot < 0 || $action->destination->slot >= 9))
                || ($action->source->container === InventoryContainer::CreatedOutput && $action->source->slot !== 50)
                || ($action->destination->container === InventoryContainer::CreatedOutput
                    && $action->destination->slot !== 50)
                || ($action->source->container === InventoryContainer::OpenedContainer
                    && ($action->source->slot < 0 || $action->source->slot > 0xff))
                || ($action->destination->container === InventoryContainer::OpenedContainer
                    && ($action->destination->slot < 0 || $action->destination->slot > 0xff))) {
                throw new CommandValidationException('Inventory stack request action is invalid.');
            }
            $validatedActions[] = $action;
        }

        return new ApplyInventoryStackRequest(
            $session,
            $requestId,
            $validatedActions,
            $rejectionReason,
            $responseMode,
            $authoritativeCreativeStack,
            $crafting,
            $workstation,
        );
    }

    private function assertOpaqueId(string $value, int $maximumBytes, string $name): void
    {
        if ($value === '' || strlen($value) > $maximumBytes || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new CommandValidationException(sprintf('Invalid %s.', $name));
        }
    }

    private function assertUtf8Text(string $value, int $maximumCharacters, int $maximumBytes, string $name): void
    {
        if (trim($value) === '' || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) {
            throw new CommandValidationException(sprintf('Invalid %s encoding or byte length.', $name));
        }
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new CommandValidationException(sprintf('%s contains control characters.', ucfirst($name)));
        }
        $result = preg_match_all('/./us', $value);
        if (!is_int($result) || $result > $maximumCharacters) {
            throw new CommandValidationException(sprintf('%s exceeds the character limit.', ucfirst($name)));
        }
    }
}
