<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\Command;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandJob;
use Bedriox\Api\Command\CommandJobSubscription;
use Bedriox\Api\Command\CommandParameterType;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\CommandSoftEnum;
use Bedriox\Api\Command\CommandSubscription;
use Bedriox\Api\Entity\Entity;
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
    /** @var array<int, CommandSoftEnumRecord> */
    private array $softEnums = [];
    /** @var array<int, int> Object ID to registry ID. */
    private array $softEnumObjects = [];
    /** @var array<string, int> Lowercase wire name to registry ID. */
    private array $softEnumNames = [];
    /** @var array<string, CommandSoftEnumUpdate> */
    private array $pendingSoftEnumUpdates = [];
    private int $nextSoftEnumId = 1;
    private int $schemaRevision = 0;
    private readonly CommandArgumentBinder $binder;

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
        private readonly int $maximumSoftEnums = 256,
        private readonly int $maximumSoftEnumsPerPlugin = 32,
        ?Closure $onlinePlayers = null,
        ?Closure $entities = null,
        ?Closure $selectorRandomIndex = null,
        ?Closure $selectorOrigin = null,
    ) {
        if ($maximumCommands < 1 || $maximumCommands > 4096
            || $maximumCommandsPerPlugin < 1 || $maximumCommandsPerPlugin > $maximumCommands
            || $maximumJobs < 1 || $maximumJobs > 1024
            || $maximumSoftEnums < 1 || $maximumSoftEnums > 1_024
            || $maximumSoftEnumsPerPlugin < 1 || $maximumSoftEnumsPerPlugin > $maximumSoftEnums) {
            throw new \InvalidArgumentException('Invalid command registry limits.');
        }
        $this->binder = new CommandArgumentBinder(
            $onlinePlayers ?? static fn(): array => [],
            $entities ?? static fn(): array => [],
            $selectorRandomIndex,
            $selectorOrigin,
        );
    }

    public function register(string $plugin, Command $command): CommandSubscription
    {
        if (!$this->plugins->isEnabled($plugin)) {
            throw new PluginException("Disabled plugin {$plugin} cannot register commands.");
        }
        if (count($this->commands) >= $this->maximumCommands) {
            throw new PluginException('The command registration limit has been reached.');
        }
        $owned = 0;
        foreach ($this->commands as $registered) {
            if ($registered->pluginOwned && strcasecmp($registered->owner, $plugin) === 0) {
                ++$owned;
            }
        }
        if ($owned >= $this->maximumCommandsPerPlugin) {
            throw new PluginException("Plugin {$plugin} reached its command registration limit.");
        }
        $definition = $command->definition();
        $arguments = $command->defineArguments();
        $this->requireOwnedSoftEnums($plugin, true, $arguments);
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
        $registered = new RegisteredCommand($id, $this->sequence++, $plugin, true, $command, $definition, $arguments);
        $this->commands[$id] = $registered;
        $this->labels[$primary] = $id;
        $this->labels[strtolower($plugin . ':' . $definition->name)] = $id;
        foreach ($definition->aliases as $alias) {
            $this->labels[strtolower($alias)] = $id;
        }
        $this->ownership->own($plugin, "command:{$id}", fn() => $this->unregister($id));
        ++$this->schemaRevision;

        return new OwnedCommandSubscription($this, $id);
    }

    public function registerServer(Command $command): CommandSubscription
    {
        if (count($this->commands) >= $this->maximumCommands) {
            throw new PluginException('The command registration limit has been reached.');
        }
        $definition = $command->definition();
        $arguments = $command->defineArguments();
        $this->requireOwnedSoftEnums('Bedriox', false, $arguments);
        $id = $this->nextId++;
        $primary = strtolower($definition->name);
        $labels = [$primary, 'bedriox:' . $primary];
        foreach ($definition->aliases as $alias) {
            $labels[] = strtolower($alias);
        }
        foreach ($labels as $label) {
            if (isset($this->labels[$label])) {
                throw new PluginException("Command label already registered: {$label}");
            }
        }
        $this->commands[$id] = new RegisteredCommand(
            $id,
            $this->sequence++,
            'Bedriox',
            false,
            $command,
            $definition,
            $arguments,
        );
        foreach ($labels as $label) {
            $this->labels[$label] = $id;
        }
        ++$this->schemaRevision;
        return new OwnedCommandSubscription($this, $id);
    }

    /** @param list<string> $values */
    public function registerSoftEnum(string $plugin, string $name, array $values = []): CommandSoftEnum
    {
        if (!$this->plugins->isEnabled($plugin)) {
            throw new PluginException("Disabled plugin {$plugin} cannot register command soft enums.");
        }
        $owned = 0;
        foreach ($this->softEnums as $record) {
            if ($record->pluginOwned && strcasecmp($record->owner, $plugin) === 0) {
                ++$owned;
            }
        }
        if ($owned >= $this->maximumSoftEnumsPerPlugin) {
            throw new PluginException("Plugin {$plugin} reached its command soft-enum registration limit.");
        }
        self::validateLocalSoftEnumName($name);
        $pluginNamespace = strtolower((string) preg_replace('/[^a-z0-9_.-]+/i', '_', $plugin));

        return $this->createSoftEnum(
            $plugin,
            true,
            "bedriox:plugin:{$pluginNamespace}:" . strtolower($name),
            $values,
        );
    }

    /** @param list<string> $values */
    public function registerServerSoftEnum(string $name, array $values = []): CommandSoftEnum
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.:-]+$/D', $name) !== 1 || strlen($name) > 128) {
            throw new PluginException('A server command soft-enum name must be canonical, namespaced, and bounded.');
        }

        return $this->createSoftEnum('Bedriox', false, strtolower($name), $values);
    }

    /** @return list<string> */
    public function softEnumValues(int $id): array
    {
        return ($this->softEnums[$id] ?? throw new PluginException('Command soft enum is no longer registered.'))->values;
    }

    /** @param list<string> $values */
    public function replaceSoftEnum(int $id, array $values): bool
    {
        $record = $this->softEnums[$id] ?? throw new PluginException('Command soft enum is no longer registered.');
        $normalized = self::normalizeSoftEnumValues($values);
        if ($record->values === $normalized) {
            return false;
        }
        $record->values = $normalized;
        $this->pendingSoftEnumUpdates[strtolower($record->name)] = new CommandSoftEnumUpdate(
            $record->name,
            $normalized,
        );

        return true;
    }

    public function hasSoftEnum(int $id): bool
    {
        return isset($this->softEnums[$id]);
    }

    public function schemaRevision(): int
    {
        return $this->schemaRevision;
    }

    /** @return list<CommandSoftEnumUpdate> */
    public function drainSoftEnumUpdates(): array
    {
        $updates = array_values($this->pendingSoftEnumUpdates);
        $this->pendingSoftEnumUpdates = [];

        return $updates;
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

            return CommandResult::failure($failure->getMessage());
        }
        $rawLabel = array_shift($tokens);
        if (!is_string($rawLabel)) {
            return CommandResult::failure('The command line did not contain a command.');
        }
        $label = strtolower($rawLabel);
        $command = isset($this->labels[$label]) ? ($this->commands[$this->labels[$label]] ?? null) : null;
        if (!$command instanceof RegisteredCommand || ($command->pluginOwned && !$this->plugins->isEnabled($command->owner))) {
            $sender->sendMessage('Unknown command.');

            return CommandResult::failure('Unknown command.');
        }
        if (!$command->definition->allowedSenders->allows($sender->type())) {
            $sender->sendMessage('This command cannot be used by this sender.');

            return CommandResult::failure('This command cannot be used by this sender.');
        }
        if ($command->definition->permission !== null && !$sender->hasPermission($command->definition->permission)) {
            $sender->sendMessage('You do not have permission to use this command.');

            return CommandResult::failure('You do not have permission to use this command.');
        }
        try {
            $values = $this->binder->bind($command->arguments, $sender, $tokens);
        } catch (CommandBindingException $failure) {
            $sender->sendMessage($failure->getMessage());
            foreach ($command->arguments->usage($command->definition->name) as $usage) {
                $sender->sendMessage('Usage: ' . $usage);
            }

            return CommandResult::failure($failure->getMessage());
        }
        $pre = new CommandPreDispatchEvent($sender, $command->definition->name, $values, $command->owner);
        $this->events->dispatch($pre);
        if ($pre->isCancelled() || !$this->has($command->id)) {
            return CommandResult::failure('Command dispatch was cancelled.');
        }
        $frame = $command->pluginOwned ? new PluginExecutionFrame(
            $command->owner,
            $this->plugins->version($command->owner),
            'command',
            listener: $command->definition->name,
            startedAtNanoseconds: hrtime(true),
        ) : null;
        if ($frame !== null) {
            $this->execution->enter($frame);
            $this->actions->begin();
        }
        try {
            $result = $command->command->execute(new CommandContext($sender, $label, $values));
            if ($command->pluginOwned && !$this->plugins->isEnabled($command->owner)) {
                $this->actions->discard();

                return CommandResult::failure('The command owner was disabled during execution.');
            }
            if ($frame !== null) {
                $this->actions->commit();
            }
        } catch (Throwable $failure) {
            if ($frame !== null && $this->actions->isCapturing()) {
                $this->actions->discard();
            }
            if ($command->pluginOwned) {
                $this->plugins->disableAfterFailure($command->owner, $failure, $frame);
            } else {
                $sender->sendMessage('The command failed internally.');
            }

            return CommandResult::failure('The command failed internally.');
        } finally {
            if ($frame !== null) {
                $this->execution->leave();
            }
        }
        if ($result->message() !== null) {
            $sender->sendMessage($result->message());
        }
        $this->events->dispatch(new CommandDispatchedEvent(
            $sender,
            $command->definition->name,
            $values,
            $command->owner,
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
        ++$this->schemaRevision;
        if ($command->pluginOwned) {
            $this->ownership->forget($command->owner, "command:{$id}");
        }
    }

    public function has(int $id): bool
    {
        return isset($this->commands[$id]);
    }

    public function count(): int
    {
        return count($this->commands);
    }

    private function unregisterSoftEnum(int $id): void
    {
        $record = $this->softEnums[$id] ?? null;
        if (!$record instanceof CommandSoftEnumRecord) {
            return;
        }
        foreach ($this->commands as $command) {
            if ($this->commandUsesSoftEnum($command, $record->handle)) {
                $this->unregister($command->id);
            }
        }
        unset(
            $this->softEnums[$id],
            $this->softEnumObjects[spl_object_id($record->handle)],
            $this->softEnumNames[strtolower($record->name)],
            $this->pendingSoftEnumUpdates[strtolower($record->name)],
        );
        if ($record->pluginOwned) {
            $this->ownership->forget($record->owner, "command-soft-enum:{$id}");
        }
    }

    /** @param list<string> $values */
    private function createSoftEnum(string $owner, bool $pluginOwned, string $name, array $values): CommandSoftEnum
    {
        if (count($this->softEnums) >= $this->maximumSoftEnums) {
            throw new PluginException('The command soft-enum registration limit has been reached.');
        }
        $key = strtolower($name);
        if (isset($this->softEnumNames[$key])) {
            throw new PluginException("Command soft-enum name already registered: {$name}");
        }
        $values = self::normalizeSoftEnumValues($values);
        $id = $this->nextSoftEnumId++;
        $handle = new RegisteredCommandSoftEnum($id, $name, $this);
        $record = new CommandSoftEnumRecord($id, $owner, $pluginOwned, $name, $values, $handle);
        $this->softEnums[$id] = $record;
        $this->softEnumObjects[spl_object_id($handle)] = $id;
        $this->softEnumNames[$key] = $id;
        if ($pluginOwned) {
            $this->ownership->own($owner, "command-soft-enum:{$id}", fn() => $this->unregisterSoftEnum($id));
        }

        return $handle;
    }

    private function requireOwnedSoftEnums(string $owner, bool $pluginOwned, \Bedriox\Api\Command\CommandArguments $arguments): void
    {
        foreach ($arguments->overloads() as $overload) {
            foreach ($overload->parameters() as $parameter) {
                if ($parameter->type() !== CommandParameterType::SOFT_ENUM) {
                    continue;
                }
                $softEnum = $parameter->softEnumValue();
                $id = $softEnum === null ? null : ($this->softEnumObjects[spl_object_id($softEnum)] ?? null);
                $record = $id === null ? null : ($this->softEnums[$id] ?? null);
                if (!$record instanceof CommandSoftEnumRecord
                    || $record->pluginOwned !== $pluginOwned
                    || strcasecmp($record->owner, $owner) !== 0) {
                    throw new PluginException('Command soft enums must be registered by the command owner.');
                }
            }
        }
    }

    private function commandUsesSoftEnum(RegisteredCommand $command, CommandSoftEnum $softEnum): bool
    {
        foreach ($command->arguments->overloads() as $overload) {
            foreach ($overload->parameters() as $parameter) {
                if ($parameter->softEnumValue() === $softEnum) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function validateLocalSoftEnumName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1) {
            throw new PluginException('A command soft-enum name must be a bounded lowercase identifier.');
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function normalizeSoftEnumValues(array $values): array
    {
        if (count($values) > 4_096) {
            throw new PluginException('A command soft enum may contain at most 4096 values.');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 256
                || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                throw new PluginException('Command soft-enum values must be valid, non-empty, bounded text.');
            }
            $key = strtolower($value);
            if (isset($normalized[$key])) {
                throw new PluginException('Command soft-enum values must be unique ignoring case.');
            }
            $normalized[$key] = $value;
        }

        return array_values($normalized);
    }

    /** @return list<CommandDefinition> */
    public function availableTo(CommandSender $sender): array
    {
        return $this->availableDefinitions($sender->type(), $sender->hasPermission(...));
    }

    /**
     * @param callable(string): bool $permissionResolver
     * @return list<CommandDefinition>
     */
    public function availableDefinitions(CommandSenderType $senderType, callable $permissionResolver): array
    {
        $definitions = [];
        foreach ($this->commands as $command) {
            if (($command->pluginOwned && !$this->plugins->isEnabled($command->owner))
                || !$command->definition->allowedSenders->allows($senderType)
                || ($command->definition->permission !== null && !$permissionResolver($command->definition->permission))) {
                continue;
            }
            $definitions[] = $command->definition;
        }
        return $definitions;
    }

    /**
     * @param callable(string): bool $permissionResolver
     * @return list<RegisteredCommand>
     */
    public function availableCommands(CommandSenderType $senderType, callable $permissionResolver): array
    {
        return array_values(array_filter(
            $this->commands,
            fn(RegisteredCommand $command): bool => (!$command->pluginOwned || $this->plugins->isEnabled($command->owner))
                && $command->definition->allowedSenders->allows($senderType)
                && ($command->definition->permission === null || $permissionResolver($command->definition->permission)),
        ));
    }
}
