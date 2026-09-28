<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\PlayerInventoryActions;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerActions;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\Packet;
use Closure;
use LogicException;

/** @internal Binds immutable public player handles to the current runtime session without exposing it. */
final class PlayerConnectionDirectory
{
    /** @var array<string, array{session: string, connected: Closure(): bool, send: Closure(Packet, bool): bool, kick: Closure(string, ?string, ?string): bool, swing: Closure(): bool, teleport: Closure(Position): bool, gameMode: Closure(GameMode): bool, give: Closure(ItemStack): bool, slot: Closure(int, ?ItemStack): bool, contents: Closure(list<ItemStack|null>): bool, remove: Closure(ItemStack): bool, select: Closure(int): bool, equipment: Closure(EquipmentSlot, ?ItemStack): bool, armor: Closure(array<string, ItemStack|null>): bool, damage: Closure(float): bool, maximumStackSize: Closure(ItemStack): int}> */
    private array $connections = [];

    /**
     * @param Closure(): bool             $connected
     * @param Closure(Packet, bool): bool $send
     * @param Closure(string, ?string, ?string): bool $kick
     * @param Closure(): bool $swingArm
     * @param null|Closure(Position): bool $teleport
     * @param null|Closure(GameMode): bool $setGameMode
     * @param null|Closure(ItemStack): bool $giveItem
     * @param null|Closure(int, ?ItemStack): bool $setInventorySlot
     * @param null|Closure(float): bool $damage
     * @param null|Closure(list<ItemStack|null>): bool $setInventoryContents
     * @param null|Closure(ItemStack): bool $removeInventoryItem
     * @param null|Closure(int): bool $setSelectedHotbarSlot
     * @param null|Closure(EquipmentSlot, ?ItemStack): bool $setEquipmentItem
     * @param null|Closure(array<string, ItemStack|null>): bool $setArmorContents
     * @param null|Closure(ItemStack): int $maximumStackSize
     */
    public function connect(
        string $identity,
        string $sessionId,
        Closure $connected,
        Closure $send,
        ?Closure $kick = null,
        ?Closure $swingArm = null,
        ?Closure $teleport = null,
        ?Closure $setGameMode = null,
        ?Closure $giveItem = null,
        ?Closure $setInventorySlot = null,
        ?Closure $damage = null,
        ?Closure $setInventoryContents = null,
        ?Closure $removeInventoryItem = null,
        ?Closure $setSelectedHotbarSlot = null,
        ?Closure $setEquipmentItem = null,
        ?Closure $setArmorContents = null,
        ?Closure $maximumStackSize = null,
    ): void {
        /** @var Closure(list<ItemStack|null>): bool $contents */
        $contents = $setInventoryContents ?? static fn(array $contents): bool => false;
        /** @var Closure(array<string, ItemStack|null>): bool $armor */
        $armor = $setArmorContents ?? static fn(array $contents): bool => false;

        $this->connections[self::key($identity)] = [
            'session' => $sessionId,
            'connected' => $connected,
            'send' => $send,
            'kick' => $kick ?? static fn(string $reason, ?string $quitMessage, ?string $screenMessage): bool => false,
            'swing' => $swingArm ?? static fn(): bool => false,
            'teleport' => $teleport ?? static fn(Position $position): bool => false,
            'gameMode' => $setGameMode ?? static fn(GameMode $gameMode): bool => false,
            'give' => $giveItem ?? static fn(ItemStack $stack): bool => false,
            'slot' => $setInventorySlot ?? static fn(int $slot, ?ItemStack $stack): bool => false,
            'contents' => $contents,
            'remove' => $removeInventoryItem ?? static fn(ItemStack $stack): bool => false,
            'select' => $setSelectedHotbarSlot ?? static fn(int $slot): bool => false,
            'equipment' => $setEquipmentItem ?? static fn(EquipmentSlot $slot, ?ItemStack $stack): bool => false,
            'armor' => $armor,
            'damage' => $damage ?? static fn(float $amount): bool => false,
            'maximumStackSize' => $maximumStackSize ?? static fn(ItemStack $stack): int => throw new LogicException('Authoritative item rules are unavailable.'),
        ];
    }

    public function disconnect(string $identity, string $sessionId): void
    {
        $key = self::key($identity);
        if (($this->connections[$key]['session'] ?? null) === $sessionId) {
            unset($this->connections[$key]);
        }
    }

    public function connection(string $identity): PlayerConnection
    {
        $key = self::key($identity);
        $session = $this->connections[$key]['session'] ?? null;

        return new PlayerConnection(
            fn(): bool => $session !== null && ($this->connections[$key]['session'] ?? null) === $session
                && ($this->connections[$key]['connected'])(),
            fn(Packet $packet, bool $immediate): bool => $session !== null && ($this->connections[$key]['session'] ?? null) === $session
                && ($this->connections[$key]['send'])($packet, $immediate),
            fn(string $reason, ?string $quitMessage, ?string $screenMessage): bool => $session !== null && ($this->connections[$key]['session'] ?? null) === $session
                && ($this->connections[$key]['kick'])($reason, $quitMessage, $screenMessage),
            fn(): bool => $session !== null && ($this->connections[$key]['session'] ?? null) === $session
                && ($this->connections[$key]['swing'])(),
        );
    }

    public function actions(string $identity): PlayerActions
    {
        $key = self::key($identity);
        $session = $this->connections[$key]['session'] ?? null;

        return new PlayerActions(
            function (Position $position) use ($key, $session): void {
                $connection = $this->connections[$key] ?? null;
                $this->requireAccepted($session !== null && $connection !== null && $connection['session'] === $session && ($connection['connected'])()
                    && ($connection['teleport'])($position));
            },
            function (GameMode $gameMode) use ($key, $session): void {
                $connection = $this->connections[$key] ?? null;
                $this->requireAccepted($session !== null && $connection !== null && $connection['session'] === $session && ($connection['connected'])()
                    && ($connection['gameMode'])($gameMode));
            },
            function (float $amount) use ($key, $session): void {
                $connection = $this->connections[$key] ?? null;
                $this->requireAccepted($session !== null && $connection !== null && $connection['session'] === $session && ($connection['connected'])()
                    && ($connection['damage'])($amount));
            },
        );
    }

    public function inventoryActions(string $identity): PlayerInventoryActions
    {
        $key = self::key($identity);
        $session = $this->connections[$key]['session'] ?? null;

        return new PlayerInventoryActions(
            function (int $slot, ?ItemStack $stack) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['slot'])($slot, $stack));
            },
            function (array $contents) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['contents'])($contents));
            },
            function (ItemStack $stack) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['give'])($stack));
            },
            function (ItemStack $stack) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['remove'])($stack));
            },
            function (int $slot) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['select'])($slot));
            },
            function (EquipmentSlot $slot, ?ItemStack $stack) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['equipment'])($slot, $stack));
            },
            function (array $contents) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['armor'])($contents));
            },
            function (?ItemStack $stack) use ($key, $session): void {
                $connection = $this->currentConnection($key, $session);
                $this->requireAccepted(($connection['equipment'])(EquipmentSlot::OFF_HAND, $stack));
            },
        );
    }

    /** @return Closure(ItemStack): int */
    public function maximumStackSize(string $identity): Closure
    {
        $key = self::key($identity);
        $session = $this->connections[$key]['session'] ?? null;

        return function (ItemStack $stack) use ($key, $session): int {
            $connection = $this->connections[$key] ?? null;
            if ($session === null || $connection === null || $connection['session'] !== $session
                || !($connection['connected'])()) {
                throw new LogicException('The inventory snapshot is no longer attached to its player session.');
            }

            return ($connection['maximumStackSize'])($stack);
        };
    }

    /** Rebinds an immutable public snapshot to its current runtime connection. */
    public function attach(Player $player): Player
    {
        return $player->withRuntime(
            $this->connection($player->uuid),
            $this->actions($player->uuid),
            $this->inventoryActions($player->uuid),
            $this->maximumStackSize($player->uuid),
        );
    }

    private function requireAccepted(bool $accepted): void
    {
        if (!$accepted) {
            throw new LogicException('The player action could not be accepted by the authoritative runtime.');
        }
    }

    /**
     * @return array{session: string, connected: Closure(): bool, send: Closure(Packet, bool): bool, kick: Closure(string, ?string, ?string): bool, swing: Closure(): bool, teleport: Closure(Position): bool, gameMode: Closure(GameMode): bool, give: Closure(ItemStack): bool, slot: Closure(int, ?ItemStack): bool, contents: Closure(list<ItemStack|null>): bool, remove: Closure(ItemStack): bool, select: Closure(int): bool, equipment: Closure(EquipmentSlot, ?ItemStack): bool, armor: Closure(array<string, ItemStack|null>): bool, damage: Closure(float): bool, maximumStackSize: Closure(ItemStack): int}
     */
    private function currentConnection(string $key, ?string $session): array
    {
        $connection = $this->connections[$key] ?? null;
        if ($session === null || $connection === null || $connection['session'] !== $session || !($connection['connected'])()) {
            throw new LogicException('The player action could not be accepted by the authoritative runtime.');
        }

        return $connection;
    }

    private static function key(string $identity): string
    {
        return strtolower($identity);
    }
}
