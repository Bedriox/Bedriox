<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Persistence;

use Bedriox\Server\Persistence\OrderedPersistenceQueue;
use Bedriox\Server\Persistence\PersistenceIpcCodec;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use LogicException;
use PHPUnit\Framework\TestCase;

final class OrderedPersistenceQueueTest extends TestCase
{
    public function testCoalescesOnlyUnsentRevisionsAndKeepsInflightIdentity(): void
    {
        $queue = new OrderedPersistenceQueue(4, 1_024, 512, 4);

        self::assertSame(PersistenceSubmission::ACCEPTED, $queue->enqueue('chunk:0:0', 1, 'one')->status);
        self::assertSame(PersistenceSubmission::COALESCED, $queue->enqueue('chunk:0:0', 2, 'two')->status);
        $first = $queue->dispatch();
        self::assertNotNull($first);
        self::assertSame(2, $first->revision);

        self::assertSame(PersistenceSubmission::ACCEPTED, $queue->enqueue('chunk:0:0', 3, 'three')->status);
        self::assertNull($queue->dispatch(), 'A second revision for one key must not run concurrently.');
        $queue->complete($first->id, $first->key, $first->revision, true);

        $completion = $queue->takeCompletion();
        self::assertNotNull($completion);
        self::assertSame(2, $completion->revision);
        self::assertTrue($completion->successful);
        self::assertSame(3, $queue->dispatch()?->revision);
    }

    public function testRejectsStaleAndMismatchedCompletions(): void
    {
        $queue = new OrderedPersistenceQueue(2, 128, 64, 2);
        $queue->enqueue('player:uuid', 8, 'profile');
        $request = $queue->dispatch();
        self::assertNotNull($request);
        self::assertSame(PersistenceSubmission::STALE, $queue->enqueue('player:uuid', 8, 'duplicate')->status);

        $this->expectException(LogicException::class);
        $queue->complete($request->id, $request->key, 7, true);
    }

    public function testSaturationDoesNotDiscardExistingQueuedRevision(): void
    {
        $queue = new OrderedPersistenceQueue(2, 30, 20, 2);
        $accepted = $queue->enqueue('a', 1, str_repeat('x', 10));
        self::assertSame(PersistenceSubmission::ACCEPTED, $accepted->status);
        self::assertSame(PersistenceSubmission::SATURATED, $queue->enqueue('b', 1, str_repeat('y', 20))->status);

        self::assertSame($accepted->request?->id, $queue->dispatch()?->id);
        self::assertSame(1, $queue->snapshot()->saturated);
    }

    public function testFailureProducesSpecificCompletionWithoutAcknowledgingNewerWork(): void
    {
        $queue = new OrderedPersistenceQueue(3, 256, 128, 3);
        $queue->enqueue('player:uuid', 4, 'old');
        $request = $queue->dispatch();
        self::assertNotNull($request);
        $queue->enqueue('player:uuid', 5, 'new');
        $queue->complete($request->id, $request->key, $request->revision, false, 'disk_full');

        $completion = $queue->takeCompletion();
        self::assertNotNull($completion);
        self::assertFalse($completion->successful);
        self::assertSame('disk_full', $completion->failureCode);
        self::assertSame(5, $queue->dispatch()?->revision);
        self::assertSame(1, $queue->snapshot()->failed);
    }

    public function testExplicitIpcCodecRoundTripsBinaryPayloadAndCompletionIdentity(): void
    {
        $queue = new OrderedPersistenceQueue(2, 256, 128, 2);
        $request = $queue->enqueue('world:example:chunk:0:0', 12, "binary\0payload")->request;
        self::assertNotNull($request);
        $codec = new PersistenceIpcCodec(128);

        self::assertEquals($request, $codec->decodeRequest($codec->encodeRequest($request)));
        $completion = new PersistenceWriteCompletion($request->id, $request->key, $request->revision, false, 'disk_full');
        self::assertEquals($completion, $codec->decodeCompletion($codec->encodeCompletion($completion)));
    }

    public function testExplicitIpcCodecRejectsUnknownFields(): void
    {
        $codec = new PersistenceIpcCodec(128);

        $this->expectException(\RuntimeException::class);
        $codec->decodeRequest('{"id":1,"key":"player:a","kind":"write","payload":"","revision":1,"sequence":1,"unknown":true,"version":1}');
    }
}
