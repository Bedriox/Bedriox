<?php

declare(strict_types=1);

namespace Bedriox\Server\Command;

use Bedriox\Api\Command\CommandSoftEnum;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\Default\BuiltinCommand;
use Bedriox\Server\Command\Default\DeopCommand;
use Bedriox\Server\Command\Default\GamemodeCommand;
use Bedriox\Server\Command\Default\GarbageCollectionStatus;
use Bedriox\Server\Command\Default\GarbageCollectorCommand;
use Bedriox\Server\Command\Default\GiveCommand;
use Bedriox\Server\Command\Default\HelpCommand;
use Bedriox\Server\Command\Default\KillCommand;
use Bedriox\Server\Command\Default\ListCommand;
use Bedriox\Server\Command\Default\OnlinePlayerResolver;
use Bedriox\Server\Command\Default\OpCommand;
use Bedriox\Server\Command\Default\PermissionCommand;
use Bedriox\Server\Command\Default\StatusCommand;
use Bedriox\Server\Command\Default\StopCommand;
use Bedriox\Server\Command\Default\SummonCommand;
use Bedriox\Server\Command\Default\TeleportCommand;
use Bedriox\Server\Command\Default\TimeCommand;
use Bedriox\Server\Command\Default\VersionCommand;
use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\Observability\PerformanceSnapshot;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\World\ChunkUnloadResult;
use Closure;

/** Coordinates registration of the server-owned default command set. */
final readonly class BuiltinCommandRegistrar
{
    /**
     * @param Closure(): list<Player> $players
     * @param Closure(): void $stop
     * @param Closure(): list<string> $itemIdentifiers
     * @param Closure(Player, \Bedriox\Api\Player\GameMode): bool|null $changeGameMode
     * @param Closure(Player, string, int): bool|null $giveItem
     * @param Closure(string): bool|null $itemExists
     * @param Closure(): PerformanceSnapshot|null $status
     * @param Closure(Player, \Bedriox\Api\World\Position, ?float, ?float): bool|null $teleport
     * @param Closure(): GarbageCollectionStatus|null $garbageCollectionStatus
     * @param Closure(): GarbageCollectionReport|null $collectGarbage
     * @param Closure(): ChunkUnloadResult|null $unloadChunks
     * @param Closure(): list<string>|null $entityIdentifiers
     * @param Closure(string, Position, ?Player): bool|null $summonEntity
     * @param Closure(): (int|null) $currentWorldTime
     * @param Closure(int): (int|null) $setWorldTime
     * @param Closure(int): (int|null) $addWorldTime
     * @param Closure(bool): (int|null) $setWorldTimeRunning
     * @param Closure(Player|\Bedriox\Api\Entity\Entity): bool|null $killTarget
     */
    public function __construct(
        private CommandRegistry $commands,
        private PermissionStore $permissions,
        private Closure $players,
        private Closure $stop,
        private Closure $itemIdentifiers,
        private ?Closure $authorityChanged = null,
        private ?Closure $changeGameMode = null,
        private ?Closure $giveItem = null,
        private ?Closure $itemExists = null,
        private ?Closure $status = null,
        private ?Closure $teleport = null,
        private ?Closure $garbageCollectionStatus = null,
        private ?Closure $collectGarbage = null,
        private ?Closure $unloadChunks = null,
        private ?Closure $entityIdentifiers = null,
        private ?Closure $summonEntity = null,
        private ?Closure $currentWorldTime = null,
        private ?Closure $setWorldTime = null,
        private ?Closure $addWorldTime = null,
        private ?Closure $setWorldTimeRunning = null,
        private ?Closure $killTarget = null,
    ) {}

    public function register(): CommandSoftEnum
    {
        $itemIdentifiers = $this->commands->registerServerSoftEnum(
            'bedriox:item_identifiers',
            ($this->itemIdentifiers)(),
        );
        $players = new OnlinePlayerResolver($this->players);
        $entityIdentifiers = $this->entityIdentifiers !== null && $this->summonEntity !== null
            ? $this->commands->registerServerSoftEnum(
                'bedriox:entity_identifiers',
                self::summonCommandIdentifiers(($this->entityIdentifiers)()),
            )
            : null;
        foreach ($this->commands($players, $itemIdentifiers, $entityIdentifiers) as $command) {
            $this->commands->registerServer($command);
        }

        return $itemIdentifiers;
    }

    /** @return list<BuiltinCommand> */
    private function commands(
        OnlinePlayerResolver $players,
        CommandSoftEnum $itemIdentifiers,
        ?CommandSoftEnum $entityIdentifiers,
    ): array {
        $commands = [
            new VersionCommand(),
            new HelpCommand($this->commands),
            new ListCommand($players),
            new StopCommand($this->stop),
            new OpCommand($this->permissions, $this->authorityChanged),
            new DeopCommand($this->permissions, $this->authorityChanged),
            new PermissionCommand($this->permissions, $this->authorityChanged),
            new GamemodeCommand($this->changeGameMode),
            new GiveCommand($itemIdentifiers, $this->giveItem, $this->itemExists),
            new TeleportCommand($this->teleport),
        ];
        if ($this->killTarget !== null) {
            $commands[] = new KillCommand($this->killTarget);
        }
        if ($entityIdentifiers !== null && $this->summonEntity !== null) {
            $commands[] = new SummonCommand($entityIdentifiers, $this->summonEntity);
        }
        if ($this->currentWorldTime !== null && $this->setWorldTime !== null
            && $this->addWorldTime !== null && $this->setWorldTimeRunning !== null) {
            $commands[] = new TimeCommand(
                $this->currentWorldTime,
                $this->setWorldTime,
                $this->addWorldTime,
                $this->setWorldTimeRunning,
            );
        }
        if ($this->status !== null) {
            $commands[] = new StatusCommand($this->status);
        }
        if ($this->garbageCollectionStatus !== null && $this->collectGarbage !== null && $this->unloadChunks !== null) {
            $commands[] = new GarbageCollectorCommand(
                $this->garbageCollectionStatus,
                $this->collectGarbage,
                $this->unloadChunks,
            );
        }

        return $commands;
    }

    /**
     * @param array<array-key, mixed> $identifiers
     * @return list<string>
     */
    private static function summonCommandIdentifiers(array $identifiers): array
    {
        if (!array_is_list($identifiers) || count($identifiers) > 512) {
            throw new \InvalidArgumentException('Summon entity identifiers must be a bounded list.');
        }
        $normalized = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier)) {
                throw new \InvalidArgumentException('Summon entity identifiers must be strings.');
            }
            $identifier = strtolower(str_contains($identifier, ':') ? $identifier : 'minecraft:' . $identifier);
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1 || strlen($identifier) > 128) {
                throw new \InvalidArgumentException('Summon entity identifier is invalid.');
            }
            if (isset($normalized[$identifier])) {
                throw new \InvalidArgumentException('Summon entity identifiers must be unique.');
            }
            $normalized[$identifier] = str_starts_with($identifier, 'minecraft:')
                ? substr($identifier, strlen('minecraft:'))
                : $identifier;
        }
        $values = array_values($normalized);
        usort($values, static fn(string $left, string $right): int =>
            [(int) str_contains($left, ':'), $left] <=> [(int) str_contains($right, ':'), $right]);

        return $values;
    }
}
