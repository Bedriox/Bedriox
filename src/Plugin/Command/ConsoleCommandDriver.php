<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Runtime\RuntimeDriver;
use Bedriox\Server\Runtime\RuntimeFailureSource;
use Bedriox\Server\Runtime\RuntimeIdleAdvisor;
use SplQueue;
use Throwable;

final class ConsoleCommandDriver implements RuntimeDriver, RuntimeFailureSource, RuntimeIdleAdvisor
{
    /** @var SplQueue<string> */
    private SplQueue $queue;

    public function __construct(
        private readonly RuntimeDriver $runtime,
        private readonly ConsoleInput $input,
        private readonly CommandRegistry $commands,
        private readonly ServerConsoleCommandSender $sender,
        private readonly ServerLogger $logger,
        private readonly int $maximumQueuedCommands = 64,
        private readonly int $maximumCommandsPerPoll = 4,
    ) {
        if ($maximumQueuedCommands < 1 || $maximumQueuedCommands > 4096
            || $maximumCommandsPerPoll < 1 || $maximumCommandsPerPoll > $maximumQueuedCommands) {
            throw new \InvalidArgumentException('Invalid console command limits.');
        }
        $this->queue = new SplQueue();
    }

    public function poll(): bool
    {
        if (!$this->runtime->poll()) {
            return false;
        }
        try {
            foreach ($this->input->readAvailable() as $line) {
                if ($this->queue->count() >= $this->maximumQueuedCommands) {
                    $this->logger->warning('Console command queue is full; input was discarded', 'Command');
                    break;
                }
                $this->queue->enqueue($line);
            }
            for ($processed = 0; $processed < $this->maximumCommandsPerPoll && !$this->queue->isEmpty(); ++$processed) {
                $this->commands->dispatch($this->sender, $this->queue->dequeue());
            }
            $this->commands->pollJobs();
        } catch (Throwable $failure) {
            $this->logger->error(sprintf('Console input failed (%s); console commands are disabled', $failure::class), 'Command');
            $this->input->close();
        }

        return true;
    }

    public function close(): void
    {
        $this->input->close();
        $this->runtime->close();
    }

    public function failure(): ?Throwable
    {
        return $this->runtime instanceof RuntimeFailureSource ? $this->runtime->failure() : null;
    }

    public function shouldIdleAfterPoll(): bool
    {
        return !$this->runtime instanceof RuntimeIdleAdvisor || $this->runtime->shouldIdleAfterPoll();
    }
}
