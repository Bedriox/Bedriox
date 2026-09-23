<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\ManagedWorkerPool;
use PHPUnit\Framework\TestCase;

final class ManagedWorkerDispatcherTest extends TestCase
{
    public function testDisabledPoolRejectsWithoutRetainingCompletion(): void
    {
        $dispatcher = new ManagedWorkerDispatcher(ManagedWorkerPool::start('test', 0));
        $called = false;
        $submission = $dispatcher->submit(
            CoreWorkerTaskCatalog::SELF_TEST,
            'payload',
            static function () use (&$called): void {
                $called = true;
            },
        );

        self::assertFalse($submission->isAccepted());
        $dispatcher->poll();
        self::assertFalse($called);
        $dispatcher->shutdown();
    }
}
