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

namespace Bedriox\Server\Worker\Task;

use Bedriox\Server\Observability\Profiling\TimingsUploadTaskCodec;
use Bedriox\Server\Worker\WorkerTaskHandler;
use RuntimeException;

final class UploadTimingsReportTask implements WorkerTaskHandler
{
    public function execute(string $payload): string
    {
        $codec = new TimingsUploadTaskCodec();
        $request = $codec->decodeRequest($payload);
        $handle = curl_init($request['endpoint']);
        if ($handle === false) {
            throw new RuntimeException('Timing report upload could not be initialized.');
        }
        $response = '';
        try {
            $configured = curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $request['report'],
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/vnd.bedriox.timings+json',
                    'Content-Encoding: gzip',
                    'Accept: application/json',
                ],
                CURLOPT_USERAGENT => 'Bedriox-Timings',
                CURLOPT_CONNECTTIMEOUT_MS => 3_000,
                CURLOPT_TIMEOUT_MS => 12_000,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $_handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > TimingsUploadTaskCodec::MAXIMUM_RESPONSE_BYTES) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (!$configured) {
                throw new RuntimeException('Timing report upload could not be configured.');
            }
            if (curl_exec($handle) !== true) {
                throw new RuntimeException('Timing report upload failed.');
            }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            if ($status !== 201 || !is_string($contentType)
                || !str_starts_with(strtolower($contentType), 'application/json')) {
                throw new RuntimeException('Timing service returned an unsupported response.');
            }
            $publication = $codec->decodeResponse($response);
            return $codec->encodeResponse($publication['id'], $publication['view_url']);
        } finally {
            unset($handle);
        }
    }
}
