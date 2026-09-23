<?php

declare(strict_types=1);

namespace Bedriox\Server\Command;

use Bedriox\Api\Player\Player;
use Bedriox\Server\Command\Default\BuiltinCommand;
use Bedriox\Server\Command\Default\DeopCommand;
use Bedriox\Server\Command\Default\GamemodeCommand;
use Bedriox\Server\Command\Default\GiveCommand;
use Bedriox\Server\Command\Default\HelpCommand;
use Bedriox\Server\Command\Default\ListCommand;
use Bedriox\Server\Command\Default\OnlinePlayerResolver;
use Bedriox\Server\Command\Default\OpCommand;
use Bedriox\Server\Command\Default\PermissionCommand;
use Bedriox\Server\Command\Default\StatusCommand;
use Bedriox\Server\Command\Default\StopCommand;
use Bedriox\Server\Command\Default\VersionCommand;
use Bedriox\Server\Observability\PerformanceSnapshot;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Closure;

/** Coordinates registration of the server-owned default command set. */
final readonly class BuiltinCommandRegistrar
{
    /**
     * @param Closure(): list<Player> $players
     * @param Closure(): void $stop
     * @param Closure(Player, \Bedriox\Api\Player\GameMode): bool|null $changeGameMode
     * @param Closure(Player, string, int): bool|null $giveItem
     * @param Closure(string): bool|null $itemExists
     * @param Closure(): PerformanceSnapshot|null $status
     */
    public function __construct(
        private CommandRegistry $commands,
        private PermissionStore $permissions,
        private Closure $players,
        private Closure $stop,
        private ?Closure $authorityChanged = null,
        private ?Closure $changeGameMode = null,
        private ?Closure $giveItem = null,
        private ?Closure $itemExists = null,
        private ?Closure $status = null,
    ) {}

    public function register(): void
    {
        $players = new OnlinePlayerResolver($this->players);
        foreach ($this->commands($players) as $command) {
            $this->commands->registerServer($command->definition(), $command->execute(...));
        }
    }

    /** @return list<BuiltinCommand> */
    private function commands(OnlinePlayerResolver $players): array
    {
        $commands = [
            new VersionCommand(),
            new HelpCommand($this->commands),
            new ListCommand($players),
            new StopCommand($this->stop),
            new OpCommand($this->permissions, $players, $this->authorityChanged),
            new DeopCommand($this->permissions, $players, $this->authorityChanged),
            new PermissionCommand($this->permissions, $players, $this->authorityChanged),
            new GamemodeCommand($players, $this->changeGameMode),
            new GiveCommand($players, $this->giveItem, $this->itemExists),
        ];
        if ($this->status !== null) {
            $commands[] = new StatusCommand($this->status);
        }

        return $commands;
    }
}
