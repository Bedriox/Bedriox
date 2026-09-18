<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandJob;
use Bedriox\Api\Command\CommandJobSubscription;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSubscription;
use Bedriox\Api\Event\Command\CommandDispatchedEvent;
use Bedriox\Api\Event\Command\CommandPreDispatchEvent;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use Throwable;

final class CommandRegistry
{
    /** @var array<int, RegisteredCommand> */
    private array $commands = [];
    /** @var array<string, int> */
    private array $labels = [];
    private int $nextId = 1;
    private int $sequence = 1;
    /** @var array<int, RegisteredCommandJob> */
    private array $jobs = [];
    private int $nextJobId = 1;
    private ?int $pollingJobId = null;
    private bool $pollingJobCancelled = false;

    public function __construct(
        private readonly PluginRuntimeControl $plugins,
        private readonly PluginExecutionContext $execution,
        private readonly PluginActionBuffer $actions,
        private readonly PluginOwnershipRegistry $ownership,
        private readonly EventDispatcher $events,
        private readonly CommandLineParser $parser = new CommandLineParser(),
        private readonly int $maximumCommands = 1024,
        private readonly int $maximumCommandsPerPlugin = 128,
        private readonly int $maximumJobs = 64,
    ) {
        if ($maximumCommands < 1 || $maximumCommands > 4096
            || $maximumCommandsPerPlugin < 1 || $maximumCommandsPerPlugin > $maximumCommands
            || $maximumJobs < 1 || $maximumJobs > 1024) {
            throw new \InvalidArgumentException('Invalid command registry limits.');
        }
    }

    public function register(string $plugin, CommandDefinition $definition, callable $handler): CommandSubscription
    {
        if (!$this->plugins->isEnabled($plugin)) {
            throw new PluginException("Disabled plugin {$plugin} cannot register commands.");
        }
        if (count($this->commands) >= $this->maximumCommands) {
            throw new PluginException('The command registration limit has been reached.');
        }
        $owned = 0;
        foreach ($this->commands as $registered) {
            if (strcasecmp($registered->plugin, $plugin) === 0) {
                ++$owned;
            }
        }
        if ($owned >= $this->maximumCommandsPerPlugin) {
            throw new PluginException("Plugin {$plugin} reached its command registration limit.");
        }
        $id = $this->nextId++;
        $primary = strtolower($definition->name);
        if (isset($this->labels[$primary])) {
            throw new PluginException("Command label already registered: {$definition->name}");
        }
        foreach ($definition->aliases as $alias) {
            if (isset($this->labels[strtolower($alias)])) {
                throw new PluginException("Command alias already registered: {$alias}");
            }
        }
        $callback = static function (CommandContext $context) use ($handler): CommandResult {
            $result = $handler($context);
            if (!$result instanceof CommandResult) {
                throw new PluginException('Command handlers must return CommandResult.');
            }

            return $result;
        };
        $command = new RegisteredCommand($id, $this->sequence++, $plugin, $definition, $callback);
        $this->commands[$id] = $command;
        $this->labels[$primary] = $id;
        $this->labels[strtolower($plugin . ':' . $definition->name)] = $id;
        foreach ($definition->aliases as $alias) {
            $this->labels[strtolower($alias)] = $id;
        }
        $this->ownership->own($plugin, "command:{$id}", fn() => $this->unregister($id));

        return new OwnedCommandSubscription($this, $id);
    }

    public function dispatch(CommandSender $sender, string $line): CommandResult
    {
        try {
            $normalized = ltrim($line);
            if (str_starts_with($normalized, '/')) {
                $normalized = substr($normalized, 1);
            }
            $tokens = $this->parser->parse($normalized);
        } catch (PluginException $failure) {
            $sender->sendMessage($failure->getMessage());

            return CommandResult::FAILURE;
        }
        $rawLabel = array_shift($tokens);
        if (!is_string($rawLabel)) {
            return CommandResult::FAILURE;
        }
        $label = strtolower($rawLabel);
        $command = isset($this->labels[$label]) ? ($this->commands[$this->labels[$label]] ?? null) : null;
        if (!$command instanceof RegisteredCommand || !$this->plugins->isEnabled($command->plugin)) {
            $sender->sendMessage('Unknown command.');

            return CommandResult::FAILURE;
        }
        if (!$command->definition->allowedSenders->allows($sender->type())) {
            $sender->sendMessage('This command cannot be used by this sender.');

            return CommandResult::FAILURE;
        }
        if ($command->definition->permission !== null && !$sender->hasPermission($command->definition->permission)) {
            $sender->sendMessage('You do not have permission to use this command.');

            return CommandResult::FAILURE;
        }
        $pre = new CommandPreDispatchEvent($sender, $command->definition->name, $tokens, $command->plugin);
        $this->events->dispatch($pre);
        if ($pre->isCancelled() || !$this->has($command->id)) {
            return CommandResult::FAILURE;
        }
        $frame = new PluginExecutionFrame(
            $command->plugin,
            $this->plugins->version($command->plugin),
            'command',
            listener: $command->definition->name,
            startedAtNanoseconds: hrtime(true),
        );
        $this->execution->enter($frame);
        $this->actions->begin();
        try {
            $result = ($command->handler)(new CommandContext($sender, $label, $tokens));
            if (!$this->plugins->isEnabled($command->plugin)) {
                $this->actions->discard();

                return CommandResult::FAILURE;
            }
            $this->actions->commit();
        } catch (Throwable $failure) {
            if ($this->actions->isCapturing()) {
                $this->actions->discard();
            }
            $this->plugins->disableAfterFailure($command->plugin, $failure, $frame);

            return CommandResult::FAILURE;
        } finally {
            $this->execution->leave();
        }
        if ($result === CommandResult::USAGE) {
            $sender->sendMessage('Usage: ' . $command->definition->usage);
        }
        $this->events->dispatch(new CommandDispatchedEvent(
            $sender,
            $command->definition->name,
            $tokens,
            $command->plugin,
            $result,
        ));

        return $result;
    }

    public function submitJob(string $plugin, CommandJob $job): CommandJobSubscription
    {
        if (!$this->plugins->isEnabled($plugin)) {
            throw new PluginException("Disabled plugin {$plugin} cannot submit command jobs.");
        }
        if (count($this->jobs) >= $this->maximumJobs) {
            throw new PluginException('The command job limit has been reached.');
        }
        $id = $this->nextJobId++;
        $this->jobs[$id] = new RegisteredCommandJob($id, $plugin, $job);
        $this->ownership->own($plugin, "command-job:{$id}", fn() => $this->cancelJob($id, false));

        return new OwnedCommandJobSubscription($this, $id);
    }

    public function pollJobs(int $maximum = 4): void
    {
        if ($maximum < 1 || $maximum > 64) {
            throw new PluginException('Command job poll limit must be between 1 and 64.');
        }
        $polled = 0;
        foreach ($this->jobs as $id => $registered) {
            if (++$polled > $maximum) {
                break;
            }
            if (!$this->plugins->isEnabled($registered->plugin)) {
                $this->cancelJob($id);
                continue;
            }
            $frame = new PluginExecutionFrame(
                $registered->plugin,
                $this->plugins->version($registered->plugin),
                'command-job',
                startedAtNanoseconds: hrtime(true),
            );
            $this->execution->enter($frame);
            $this->pollingJobId = $id;
            $this->pollingJobCancelled = false;
            try {
                if ($registered->job->poll()) {
                    unset($this->jobs[$id]);
                    $this->ownership->forget($registered->plugin, "command-job:{$id}");
                } elseif (!$this->pollingJobCancelled) {
                    unset($this->jobs[$id]);
                    $this->jobs[$id] = $registered;
                }
            } catch (Throwable $failure) {
                unset($this->jobs[$id]);
                $this->ownership->forget($registered->plugin, "command-job:{$id}");
                $this->plugins->disableAfterFailure($registered->plugin, $failure, $frame);
            } finally {
                $this->pollingJobId = null;
                $this->pollingJobCancelled = false;
                $this->execution->leave();
            }
        }
    }

    public function cancelJob(int $id, bool $forgetOwnership = true): void
    {
        $registered = $this->jobs[$id] ?? null;
        if (!$registered instanceof RegisteredCommandJob) {
            return;
        }
        unset($this->jobs[$id]);
        if ($this->pollingJobId === $id) {
            $this->pollingJobCancelled = true;
        }
        if ($forgetOwnership) {
            $this->ownership->forget($registered->plugin, "command-job:{$id}");
        }
        try {
            $registered->job->cancel();
        } catch (Throwable $failure) {
            if ($this->plugins->isEnabled($registered->plugin)) {
                $this->plugins->disableAfterFailure($registered->plugin, $failure, $this->execution->current());
            }
        }
    }

    public function hasJob(int $id): bool
    {
        return isset($this->jobs[$id]);
    }

    public function unregister(int $id): void
    {
        $command = $this->commands[$id] ?? null;
        if (!$command instanceof RegisteredCommand) {
            return;
        }
        foreach ($this->labels as $label => $owner) {
            if ($owner === $id) {
                unset($this->labels[$label]);
            }
        }
        unset($this->commands[$id]);
        $this->ownership->forget($command->plugin, "command:{$id}");
    }

    public function has(int $id): bool
    {
        return isset($this->commands[$id]);
    }

    public function count(): int
    {
        return count($this->commands);
    }
}
