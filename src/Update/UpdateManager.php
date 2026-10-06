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

namespace Bedriox\Server\Update;

use Bedriox\Api\Event\Server\UpdateAvailableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\TextFormat;
use Bedriox\Api\Update\UpdateChannel;
use Bedriox\Api\Update\UpdateInfo;
use Bedriox\Api\Update\UpdateService;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Closure;
use Throwable;

final class UpdateManager implements UpdateService
{
    private const int INITIAL_DELAY_NANOSECONDS = 1_000_000_000;
    private const int RETRY_DELAY_NANOSECONDS = 60_000_000_000;
    private const int CHECK_INTERVAL_NANOSECONDS = 21_600_000_000_000;
    private const int MAXIMUM_JITTER_SECONDS = 300;

    private readonly SemanticVersion $currentVersion;
    private readonly UpdateChannel $updateChannel;
    private readonly Closure $players;
    private readonly Closure $hasPermission;
    private readonly Closure $dispatch;
    private readonly Closure $notice;
    private readonly Closure $debug;
    private readonly Closure $clock;
    private readonly Closure $jitter;
    private ?UpdateInfo $latest = null;
    private ?string $etag = null;
    private ?string $lastModified = null;
    private ?string $announcedVersion = null;
    private ?string $lastFailure = null;
    /** @var array<string, true> */
    private array $notifiedConnections = [];
    private int $nextCheckAt;
    private bool $pending = false;

    /**
     * @param Closure(): list<Player> $players
     * @param Closure(string, string): bool $hasPermission
     * @param Closure(UpdateAvailableEvent): void $dispatch
     * @param Closure(string): void $notice
     * @param Closure(string): void $debug
     * @param null|Closure(): int $clock
     * @param null|Closure(int, int): int $jitter
     */
    public function __construct(
        string $currentVersion,
        private readonly bool $enabled,
        private readonly bool $notifyOperators,
        private readonly WorkerDispatcher $workers,
        Closure $players,
        Closure $hasPermission,
        Closure $dispatch,
        Closure $notice,
        Closure $debug,
        ?Closure $clock = null,
        ?Closure $jitter = null,
    ) {
        $this->currentVersion = SemanticVersion::parse($currentVersion);
        $this->updateChannel = $this->currentVersion->channel();
        $this->players = $players;
        $this->hasPermission = $hasPermission;
        $this->dispatch = $dispatch;
        $this->notice = $notice;
        $this->debug = $debug;
        $this->clock = $clock ?? static fn(): int => hrtime(true);
        $this->jitter = $jitter ?? static fn(int $minimum, int $maximum): int => random_int($minimum, $maximum);
        $this->nextCheckAt = ($this->clock)() + self::INITIAL_DELAY_NANOSECONDS;
    }

    public function channel(): UpdateChannel
    {
        return $this->updateChannel;
    }

    public function latestAvailable(): ?UpdateInfo
    {
        return $this->latest;
    }

    public function tick(): void
    {
        $this->notifyOnlineOperators();
        $now = ($this->clock)();
        if (!$this->enabled || $this->pending || $now < $this->nextCheckAt) {
            return;
        }
        $payload = (new UpdateTaskCodec())->encodeRequest($this->updateChannel, $this->etag, $this->lastModified);
        $submission = $this->workers->submit(
            CoreWorkerTaskCatalog::CHECK_FOR_UPDATES,
            $payload,
            $this->complete(...),
            $now + 5_000_000_000,
        );
        if (!$submission->isAccepted()) {
            $this->nextCheckAt = $now + self::RETRY_DELAY_NANOSECONDS;
            $rejection = $submission->rejection;
            $this->failure('worker_' . ($rejection === null ? 'rejected' : $rejection->value));

            return;
        }
        $this->pending = true;
    }

    private function complete(WorkerResult $result): void
    {
        $this->pending = false;
        $this->nextCheckAt = ($this->clock)() + self::CHECK_INTERVAL_NANOSECONDS
            + ($this->jitter)(0, self::MAXIMUM_JITTER_SECONDS) * 1_000_000_000;
        if ($result->status !== WorkerResultStatus::SUCCESS) {
            $this->failure('worker_' . $result->status->value);

            return;
        }
        try {
            $response = (new UpdateTaskCodec())->decodeResponse($result->payload);
            if ($response['etag'] !== null) {
                $this->etag = $response['etag'];
            }
            if ($response['last_modified'] !== null) {
                $this->lastModified = $response['last_modified'];
            }
            if ($response['status'] === 304) {
                $this->lastFailure = null;

                return;
            }
            $update = (new UpdateDocumentDecoder())->decode($response['body'], $this->updateChannel);
            $this->lastFailure = null;
            if (SemanticVersion::parse($update->version)->compare($this->currentVersion) <= 0) {
                $this->latest = null;
                $this->notifiedConnections = [];

                return;
            }
            $newVersion = $this->latest?->version !== $update->version;
            $this->latest = $update;
            if ($newVersion) {
                $this->notifiedConnections = [];
            }
            if ($this->announcedVersion !== $update->version) {
                $this->announcedVersion = $update->version;
                ($this->notice)(sprintf(
                    'Bedriox %s is available. Download it from %s',
                    $update->version,
                    $update->releaseUrl,
                ));
                ($this->dispatch)(new UpdateAvailableEvent($update));
            }
            $this->notifyOnlineOperators();
        } catch (Throwable $failure) {
            $this->failure('invalid_response_' . $failure::class);
        }
    }

    private function notifyOnlineOperators(): void
    {
        $update = $this->latest;
        if (!$this->notifyOperators || $update === null) {
            return;
        }
        foreach (($this->players)() as $player) {
            if (!($this->hasPermission)($player->uuid, 'bedriox.update.notify')) {
                continue;
            }
            $key = strtolower($player->uuid) . ':' . spl_object_id($player->connection());
            if (isset($this->notifiedConnections[$key])) {
                continue;
            }
            $player->sendMessage(TextFormat::YELLOW . sprintf(
                'Bedriox %s is available. %s',
                $update->version,
                $update->releaseUrl,
            ));
            $this->notifiedConnections[$key] = true;
        }
        if (count($this->notifiedConnections) > 4_096) {
            $this->notifiedConnections = [];
        }
    }

    private function failure(string $code): void
    {
        if ($this->lastFailure === $code) {
            return;
        }
        $this->lastFailure = $code;
        ($this->debug)('Update check was skipped (' . $code . ').');
    }
}
