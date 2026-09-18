<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\BreakBlock;
use Bedriox\Server\Simulation\Command\DisconnectPlayer;
use Bedriox\Server\Simulation\Command\JoinPlayer;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\PerformEmote;
use Bedriox\Server\Simulation\Command\PlaceBlock;
use Bedriox\Server\Simulation\Command\SelectHotbarSlot;
use Bedriox\Server\Simulation\Command\SendChat;
use Bedriox\Server\Simulation\Command\SendPluginMessage;
use Bedriox\Server\Simulation\Command\SetPluginBlock;
use Bedriox\Server\Simulation\Command\SetPluginInventorySlot;
use Bedriox\Server\Simulation\Command\TeleportPlayer;
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
    ): MovePlayer {
        $this->assertOpaqueId($session, 128, 'session');
        if ($sequence < 0) {
            throw new CommandValidationException('Movement sequence cannot be negative.');
        }
        foreach (['x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw, 'pitch' => $pitch, 'head yaw' => $headYaw ?? $yaw, 'delta x' => $deltaX, 'delta y' => $deltaY, 'delta z' => $deltaZ] as $name => $value) {
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
        );
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

    public function teleport(string $session, float $x, float $y, float $z): TeleportPlayer
    {
        $this->assertOpaqueId($session, 128, 'session');
        foreach (['x' => $x, 'y' => $y, 'z' => $z] as $name => $value) {
            if (!is_finite($value)) {
                throw new CommandValidationException("Teleport {$name} must be finite.");
            }
        }
        if (abs($x) > $this->limits->maximumCoordinate || abs($z) > $this->limits->maximumCoordinate
            || $y < -64.0 || $y > 319.0) {
            throw new CommandValidationException('Teleport position exceeds the world boundary.');
        }

        return new TeleportPlayer($session, new Position($x, $y, $z));
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

    public function emote(string $session, string $emoteId): PerformEmote
    {
        $this->assertOpaqueId($session, 128, 'session');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $emoteId) !== 1) {
            throw new CommandValidationException('Emote ID must be a canonical lowercase UUID.');
        }

        return new PerformEmote($session, $emoteId);
    }

    public function disconnect(string $session): DisconnectPlayer
    {
        $this->assertOpaqueId($session, 128, 'session');

        return new DisconnectPlayer($session);
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

    /** @param list<mixed> $actions */
    public function inventoryStackRequest(
        string $session,
        int $requestId,
        array $actions,
        ?string $rejectionReason = null,
        InventoryResponseMode $responseMode = InventoryResponseMode::ItemStackResponse,
    ): ApplyInventoryStackRequest {
        $this->assertOpaqueId($session, 128, 'session');
        if ($actions === [] && $rejectionReason === null) {
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
                    && ($action->source->slot < 0 || $action->source->slot >= 36))
                || ($action->destination->container === InventoryContainer::Main
                    && ($action->destination->slot < 0 || $action->destination->slot >= 36))
                || ($action->source->container === InventoryContainer::Cursor && $action->source->slot !== 0)
                || ($action->destination->container === InventoryContainer::Cursor && $action->destination->slot !== 0)) {
                throw new CommandValidationException('Inventory stack request action is invalid.');
            }
            $validatedActions[] = $action;
        }

        return new ApplyInventoryStackRequest($session, $requestId, $validatedActions, $rejectionReason, $responseMode);
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
