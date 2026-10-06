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

use Bedriox\Server\Update\UpdateTaskCodec;
use Bedriox\Server\Worker\WorkerTaskHandler;
use RuntimeException;

final class CheckForUpdatesTask implements WorkerTaskHandler
{
    private const string ENDPOINT = 'https://update.bedriox.com/v1/channels/';
    private const int MAXIMUM_BODY_BYTES = 16_384;
    private const int MAXIMUM_HEADER_BYTES = 8_192;

    public function execute(string $payload): string
    {
        $codec = new UpdateTaskCodec();
        $request = $codec->decodeRequest($payload);
        $handle = curl_init(self::ENDPOINT . $request['channel']->value);
        if ($handle === false) {
            throw new RuntimeException('Update request could not be initialized.');
        }
        $body = '';
        $headerBytes = 0;
        $etag = null;
        $lastModified = null;
        $headers = ['Accept: application/json'];
        if ($request['etag'] !== null) {
            $headers[] = 'If-None-Match: ' . $request['etag'];
        }
        if ($request['last_modified'] !== null) {
            $headers[] = 'If-Modified-Since: ' . $request['last_modified'];
        }
        try {
            $configured = curl_setopt_array($handle, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'Bedriox-Update-Checker',
                CURLOPT_CONNECTTIMEOUT_MS => 2_000,
                CURLOPT_TIMEOUT_MS => 4_000,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerBytes, &$etag, &$lastModified): int {
                    $length = strlen($line);
                    $headerBytes += $length;
                    if ($headerBytes > self::MAXIMUM_HEADER_BYTES) {
                        return 0;
                    }
                    $separator = strpos($line, ':');
                    if ($separator !== false) {
                        $name = strtolower(trim(substr($line, 0, $separator)));
                        $value = trim(substr($line, $separator + 1));
                        if ($name === 'etag') {
                            $etag = $value;
                        } elseif ($name === 'last-modified') {
                            $lastModified = $value;
                        }
                    }

                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAXIMUM_BODY_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            if (!$configured || curl_exec($handle) !== true) {
                throw new RuntimeException('Update request failed.');
            }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            if (!in_array($status, [200, 304], true)
                || ($status === 200 && (!is_string($contentType) || !str_starts_with(strtolower($contentType), 'application/json')))) {
                throw new RuntimeException('Update service returned an unsupported response.');
            }

            return $codec->encodeResponse([
                'status' => $status,
                'etag' => $etag,
                'last_modified' => $lastModified,
                'body' => $status === 304 ? '' : $body,
            ]);
        } finally {
            unset($handle);
        }
    }
}
