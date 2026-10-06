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

namespace Bedriox\Server\Tests\Update;

use Bedriox\Api\Event\Server\UpdateAvailableEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\Update\UpdateChannel;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\TextPacket;
use Bedriox\Server\Update\SemanticVersion;
use Bedriox\Server\Update\UpdateDocumentDecoder;
use Bedriox\Server\Update\UpdateManager;
use Bedriox\Server\Update\UpdateTaskCodec;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UpdateManagerTest extends TestCase
{
    public function testSemanticVersionsUsePrereleasePrecedenceAndSelectTheBetaChannel(): void
    {
        self::assertSame(UpdateChannel::BETA, SemanticVersion::parse('1.0.0-beta.1')->channel());
        self::assertGreaterThan(
            0,
            SemanticVersion::parse('1.0.0-beta.10')->compare(SemanticVersion::parse('1.0.0-beta.2')),
        );
        self::assertGreaterThan(0, SemanticVersion::parse('1.0.0')->compare(SemanticVersion::parse('1.0.0-beta.99')));
    }

    public function testDocumentDecoderRejectsWrongChannelsAndUnsafeUrls(): void
    {
        $document = $this->document('stable', '1.0.1');
        $document['release_url'] = 'https://example.com/release';
        $this->expectException(InvalidArgumentException::class);
        (new UpdateDocumentDecoder())->decode(
            json_encode($document, JSON_THROW_ON_ERROR),
            UpdateChannel::STABLE,
        );
    }

    public function testDocumentDecoderRejectsAnUnsupportedPrereleaseFromTheBetaEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UpdateDocumentDecoder())->decode(
            json_encode($this->document('beta', '1.0.0-alpha.1'), JSON_THROW_ON_ERROR),
            UpdateChannel::BETA,
        );
    }

    public function testAsynchronousCompletionPublishesOneEventAndNotifiesAnOperatorOnce(): void
    {
        $now = 0;
        $packets = [];
        $player = $this->player($packets);
        $workers = new CapturingUpdateWorkerDispatcher();
        $events = [];
        $notices = [];
        $manager = new UpdateManager(
            '1.0.0-beta.1',
            true,
            true,
            $workers,
            static fn(): array => [$player],
            static fn(string $uuid, string $permission): bool => $permission === 'bedriox.update.notify',
            static function (UpdateAvailableEvent $event) use (&$events): void {
                $events[] = $event;
            },
            static function (string $message) use (&$notices): void {
                $notices[] = $message;
            },
            static function (string $message): void {},
            static function () use (&$now): int {
                return $now;
            },
            static fn(int $minimum, int $maximum): int => 0,
        );

        $manager->tick();
        self::assertSame(0, $workers->submitted);
        $now = 1_000_000_000;
        $manager->tick();
        self::assertSame(1, $workers->submitted);
        self::assertSame('beta', (new UpdateTaskCodec())->decodeRequest($workers->payload)['channel']->value);

        $workers->complete($this->document('beta', '1.0.0-beta.2'));
        self::assertSame('1.0.0-beta.2', $manager->latestAvailable()?->version);
        self::assertCount(1, $events);
        self::assertCount(1, $notices);
        self::assertCount(1, $packets);
        self::assertInstanceOf(TextPacket::class, $packets[0]);
        self::assertStringContainsString('1.0.0-beta.2', $packets[0]->message);

        $manager->tick();
        self::assertCount(1, $packets);
    }

    public function testCurrentOrOlderVersionsDoNotBecomeAvailable(): void
    {
        $now = 1_000_000_000;
        $workers = new CapturingUpdateWorkerDispatcher();
        $manager = new UpdateManager(
            '1.0.0',
            true,
            true,
            $workers,
            static fn(): array => [],
            static fn(string $uuid, string $permission): bool => false,
            static function (UpdateAvailableEvent $event): void {},
            static function (string $message): void {},
            static function (string $message): void {},
            static function () use (&$now): int {
                return $now;
            },
            static fn(int $minimum, int $maximum): int => 0,
        );
        $now = 2_000_000_000;
        $manager->tick();
        $workers->complete($this->document('stable', '1.0.0'));

        self::assertNull($manager->latestAvailable());
    }

    /** @param list<Packet> $packets */
    private function player(array &$packets): Player
    {
        $connection = new PlayerConnection(
            static fn(): bool => true,
            static function (Packet $packet, bool $immediate) use (&$packets): bool {
                $packets[] = $packet;

                return true;
            },
        );

        return new Player(
            'Operator',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
            playerConnection: $connection,
        );
    }

    /** @return array<string, mixed> */
    private function document(string $channel, string $version): array
    {
        return [
            'schema' => 1,
            'product' => 'bedriox',
            'channel' => $channel,
            'version' => $version,
            'published_at' => '2026-10-06T12:00:00+00:00',
            'release_url' => "https://github.com/Bedriox/Bedriox/releases/tag/v{$version}",
            'download_url' => "https://github.com/Bedriox/Bedriox/releases/download/v{$version}/Bedriox.phar",
        ];
    }
}

final class CapturingUpdateWorkerDispatcher implements WorkerDispatcher
{
    public int $submitted = 0;
    public string $payload = '';
    private ?Closure $completion = null;
    private ?WorkerReceipt $receipt = null;

    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        ++$this->submitted;
        $this->payload = $payload;
        $this->completion = $completion;
        $this->receipt = new WorkerReceipt('test', $this->submitted, $taskTypeId, 'update-check', $deadlineNanoseconds ?? PHP_INT_MAX);

        return WorkerSubmission::accepted($this->receipt);
    }

    /** @param array<string, mixed> $document */
    public function complete(array $document): void
    {
        $completion = $this->completion;
        $receipt = $this->receipt;
        if ($completion === null || $receipt === null) {
            throw new \LogicException('No update task is pending.');
        }
        $response = (new UpdateTaskCodec())->encodeResponse([
            'status' => 200,
            'etag' => '"test"',
            'last_modified' => 'Tue, 06 Oct 2026 12:00:00 GMT',
            'body' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        $completion(new WorkerResult($receipt, WorkerResultStatus::SUCCESS, $response));
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return false;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('test', 1, 0, 0, 0, 0, $this->submitted, 0, 0, 0, 0, 0, 0, true);
    }

    public function shutdown(): void {}
}
