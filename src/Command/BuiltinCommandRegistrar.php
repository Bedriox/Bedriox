<?php

declare(strict_types=1);

namespace Bedriox\Server\Command;

use Bedriox\Api\Command\AllowedCommandSenders;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Player\Player;
use Bedriox\Server\BuildInfo;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Closure;

final readonly class BuiltinCommandRegistrar
{
    /**
     * @param Closure(): list<Player> $players
     * @param Closure(): void $stop
     */
    public function __construct(
        private CommandRegistry $commands,
        private PermissionStore $permissions,
        private Closure $players,
        private Closure $stop,
    ) {}

    public function register(): void
    {
        $this->commands->registerServer(
            new CommandDefinition('version', 'Shows Bedriox and protocol version information.', 'version', aliases: ['ver']),
            function (CommandContext $context): CommandResult {
                if ($context->arguments() !== []) {
                    return CommandResult::USAGE;
                }
                foreach (BuildInfo::current()->publicSummary() as $line) {
                    $context->sender()->sendMessage($line);
                }
                return CommandResult::SUCCESS;
            },
        );
        $this->commands->registerServer(
            new CommandDefinition('help', 'Lists commands available to you.', 'help', aliases: ['commands']),
            function (CommandContext $context): CommandResult {
                if ($context->arguments() !== []) {
                    return CommandResult::USAGE;
                }
                $definitions = $this->commands->availableTo($context->sender());
                $context->sender()->sendMessage('Available commands (' . count($definitions) . '):');
                foreach ($definitions as $definition) {
                    $context->sender()->sendMessage('/' . $definition->name . ' - ' . $definition->description);
                }
                return CommandResult::SUCCESS;
            },
        );
        $this->commands->registerServer(
            new CommandDefinition('list', 'Lists connected players.', 'list'),
            function (CommandContext $context): CommandResult {
                if ($context->arguments() !== []) {
                    return CommandResult::USAGE;
                }
                $players = ($this->players)();
                $names = array_map(static fn(Player $player): string => $player->name, $players);
                sort($names, SORT_NATURAL | SORT_FLAG_CASE);
                $context->sender()->sendMessage(count($names) . ' online: ' . ($names === [] ? 'none' : implode(', ', $names)));
                return CommandResult::SUCCESS;
            },
        );
        $this->commands->registerServer(
            new CommandDefinition(
                'stop',
                'Stops the server cleanly.',
                'stop',
                permission: 'bedriox.command.stop',
            ),
            function (CommandContext $context): CommandResult {
                if ($context->arguments() !== []) {
                    return CommandResult::USAGE;
                }
                $context->sender()->sendMessage('Stopping the server...');
                ($this->stop)();
                return CommandResult::SUCCESS;
            },
        );
        $this->commands->registerServer(
            new CommandDefinition('op', 'Grants operator authority to an online player.', 'op <player>', permission: 'bedriox.command.op'),
            fn(CommandContext $context): CommandResult => $this->operator($context, true),
        );
        $this->commands->registerServer(
            new CommandDefinition('deop', 'Removes operator authority from an online player.', 'deop <player>', permission: 'bedriox.command.op'),
            fn(CommandContext $context): CommandResult => $this->operator($context, false),
        );
        $this->commands->registerServer(
            new CommandDefinition(
                'permission',
                'Views or changes an online player permission assignment.',
                'permission <list|grant|revoke> <player> [node]',
                aliases: ['perm'],
                permission: 'bedriox.command.permission',
            ),
            fn(CommandContext $context): CommandResult => $this->permission($context),
        );
    }

    private function operator(CommandContext $context, bool $operator): CommandResult
    {
        $arguments = $context->arguments();
        if (count($arguments) !== 1) {
            return CommandResult::USAGE;
        }
        $player = $this->findOnline($arguments[0]);
        if ($player === null) {
            $context->sender()->sendMessage('Player is not online.');
            return CommandResult::FAILURE;
        }
        $changed = $this->permissions->setOperator($player->uuid, $player->name, $operator);
        $context->sender()->sendMessage($changed
            ? ($operator ? "{$player->name} is now an operator." : "{$player->name} is no longer an operator.")
            : ($operator ? "{$player->name} is already an operator." : "{$player->name} is not an operator."));
        return CommandResult::SUCCESS;
    }

    private function permission(CommandContext $context): CommandResult
    {
        $arguments = $context->arguments();
        if (count($arguments) < 2 || count($arguments) > 3) {
            return CommandResult::USAGE;
        }
        $operation = strtolower($arguments[0]);
        $player = $this->findOnline($arguments[1]);
        if ($player === null) {
            $context->sender()->sendMessage('Player is not online.');
            return CommandResult::FAILURE;
        }
        if ($operation === 'list' && count($arguments) === 2) {
            $grants = $this->permissions->grants($player->uuid);
            $context->sender()->sendMessage("Permissions for {$player->name}: " . ($grants === [] ? 'none' : implode(', ', $grants)));
            return CommandResult::SUCCESS;
        }
        if (($operation !== 'grant' && $operation !== 'revoke') || count($arguments) !== 3) {
            return CommandResult::USAGE;
        }
        $changed = $operation === 'grant'
            ? $this->permissions->grant($player->uuid, $player->name, $arguments[2])
            : $this->permissions->revoke($player->uuid, $arguments[2]);
        $context->sender()->sendMessage($changed ? 'Permission assignment updated.' : 'Permission assignment was already unchanged.');
        return CommandResult::SUCCESS;
    }

    private function findOnline(string $name): ?Player
    {
        foreach (($this->players)() as $player) {
            if (strcasecmp($player->name, $name) === 0) {
                return $player;
            }
        }
        return null;
    }
}
