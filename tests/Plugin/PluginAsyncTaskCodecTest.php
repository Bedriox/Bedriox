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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Scheduler\AsyncTask;
use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Server\Plugin\PluginArchiveIdentity;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskRequest;
use Bedriox\Server\Plugin\Scheduler\Worker\PluginAsyncTaskCodec;
use PHPUnit\Framework\TestCase;

final class PluginAsyncTaskCodecTest extends TestCase
{
    public function testRequestAndSuccessRoundTripPreserveBoundedValues(): void
    {
        $codec = new PluginAsyncTaskCodec();
        $request = new AsyncTaskRequest(
            7,
            'Example',
            '1.0.0',
            3,
            self::identity(),
            CodecTestAsyncTask::class,
            new AsyncTaskValue(['numbers' => [1, 2, 3], 'ratio' => 1.5]),
            hrtime(true) + 1_000_000_000,
        );

        $decoded = $codec->decodeRequest($codec->encodeRequest($request));
        self::assertSame('Example', $decoded['owner']);
        self::assertSame(3, $decoded['generation']);
        self::assertEquals(self::identity(), $decoded['package']);
        self::assertSame($request->input->value(), $decoded['input']->value());

        $result = $codec->decodeResult($codec->encodeSuccess(
            'Example',
            3,
            new AsyncTaskValue(['answer' => 42]),
        ));
        self::assertInstanceOf(AsyncTaskValue::class, $resultValue = $result['result'] ?? null);
        self::assertSame(['answer' => 42], $resultValue->value());
    }

    public function testPackageOwnerMustMatchTaskOwner(): void
    {
        $codec = new PluginAsyncTaskCodec();
        $request = new AsyncTaskRequest(
            1,
            'DifferentOwner',
            '1.0.0',
            1,
            self::identity(),
            CodecTestAsyncTask::class,
            new AsyncTaskValue(null),
            hrtime(true) + 1_000_000_000,
        );

        $this->expectException(\InvalidArgumentException::class);
        $codec->decodeRequest($codec->encodeRequest($request));
    }

    public function testFailureResultContainsNoExceptionMessageOrPayload(): void
    {
        $codec = new PluginAsyncTaskCodec();
        $payload = $codec->encodeFailure('Example', 2, \RuntimeException::class);
        $decoded = $codec->decodeResult($payload);

        self::assertIsString($failure = $decoded['failure'] ?? null);
        self::assertSame(\RuntimeException::class, $failure);
        self::assertStringNotContainsString('secret', $payload);
    }

    private static function identity(): PluginArchiveIdentity
    {
        return new PluginArchiveIdentity(
            __FILE__ . '.phar',
            'Example',
            '1.0.0',
            str_repeat('a', 64),
            'SHA-256',
            str_repeat('b', 64),
        );
    }
}

final class CodecTestAsyncTask extends AsyncTask
{
    public function onRun(AsyncTaskValue $input): AsyncTaskValue
    {
        return $input;
    }
}
