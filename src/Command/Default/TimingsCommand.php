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

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\TextFormat;
use Bedriox\Server\Observability\Profiling\PerformanceProfiler;
use Throwable;

final readonly class TimingsCommand implements BuiltinCommand
{
    public function __construct(private PerformanceProfiler $profiler) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'timings',
            'Records and publishes bounded server performance reports.',
            permission: 'bedriox.command.timings',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('on'))
                ->addArgument(CommandParameter::integer('seconds')->minimum(1)->maximum(PerformanceProfiler::MAXIMUM_DURATION_SECONDS)->optional(120)))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('start'))
                ->addArgument(CommandParameter::integer('seconds')->minimum(1)->maximum(PerformanceProfiler::MAXIMUM_DURATION_SECONDS)->optional(120)))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('stop')))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('status')))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('reset')))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('report')))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('paste')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        try {
            $values = $context->values();
            if ($values->has('on') || $values->has('start')) {
                return $this->start($context);
            }
            if ($values->has('stop')) {
                return CommandResult::success('Timing report saved to ' . $this->profiler->stop());
            }
            if ($values->has('status')) {
                return $this->status();
            }
            if ($values->has('reset')) {
                return $this->reset();
            }
            if ($values->has('report')) {
                return $this->report();
            }
            return $this->paste($context);
        } catch (Throwable $failure) {
            return CommandResult::failure($failure->getMessage());
        }
    }

    private function start(CommandContext $context): CommandResult
    {
        $seconds = $context->values()->has('seconds') ? $context->values()->integer('seconds') : 120;
        $this->profiler->start($seconds);
        return CommandResult::success(sprintf('Started a bounded %d-second performance profile.', $seconds));
    }

    private function status(): CommandResult
    {
        if (!$this->profiler->active()) {
            return CommandResult::success($this->profiler->lastReport() === null
                ? 'The performance profiler is idle and no report is available.'
                : 'The performance profiler is idle. Latest report: ' . $this->profiler->lastReport());
        }
        return CommandResult::success(sprintf(
            'Performance profile is active: %d/%d seconds captured.',
            $this->profiler->elapsedSeconds(),
            $this->profiler->configuredDurationSeconds(),
        ));
    }

    private function reset(): CommandResult
    {
        $this->profiler->reset();
        return CommandResult::success('The active timing profile and unpublished report were cleared.');
    }

    private function report(): CommandResult
    {
        $path = $this->profiler->active() ? $this->profiler->stop() : $this->profiler->lastReport();
        return $path === null
            ? CommandResult::failure('No timing report is available.')
            : CommandResult::success('Latest timing report: ' . $path);
    }

    private function paste(CommandContext $context): CommandResult
    {
        $sender = $context->sender();
        $accepted = $this->profiler->paste(static function (?string $url, ?string $linkPath, ?string $error) use ($sender): void {
            if ($url === null) {
                $sender->sendMessage(TextFormat::RED . ($error ?? 'The timing report upload failed.'));
                return;
            }
            $sender->sendMessage(TextFormat::GREEN . 'Timing report: ' . TextFormat::AQUA . $url);
            if ($linkPath !== null) {
                $sender->sendMessage(TextFormat::GRAY . 'The link is also saved in ' . TextFormat::AQUA . $linkPath);
            }
            if ($error !== null) {
                $sender->sendMessage(TextFormat::YELLOW . $error);
            }
        });
        return $accepted
            ? CommandResult::success('Uploading the timing report in the background...')
            : CommandResult::failure('Timing uploads are disabled, busy, or no report is available.');
    }
}
