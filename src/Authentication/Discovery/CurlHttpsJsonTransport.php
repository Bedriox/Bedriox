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

namespace Bedriox\Server\Authentication\Discovery;

use CurlHandle;

final class CurlHttpsJsonTransport implements HttpsJsonTransport
{
    public function get(string $url, int $maximumResponseBytes): string
    {
        EndpointPolicy::assertAllowed($url);
        if ($maximumResponseBytes < 1) {
            throw new DiscoveryException('Response byte limit is invalid.');
        }
        $handle = curl_init($url);
        if (!$handle instanceof CurlHandle) {
            throw new DiscoveryException('HTTPS transport is unavailable.');
        }
        $body = '';
        $overflow = false;
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Bedriox/0.3 discovery',
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $unused, string $chunk) use (&$body, &$overflow, $maximumResponseBytes): int {
                if (strlen($chunk) > $maximumResponseBytes - strlen($body)) {
                    $overflow = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = (int) constant('CURLSSLOPT_NATIVE_CA');
        }
        $configured = curl_setopt_array($handle, $options);
        if (!$configured) {
            unset($handle);
            throw new DiscoveryException('HTTPS transport policy could not be configured.');
        }
        try {
            $success = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($success !== true || $overflow || $status !== 200) {
                throw new DiscoveryException('HTTPS discovery request failed.');
            }
            return $body;
        } finally {
            // CurlHandle owns the native handle; releasing the last reference closes it without PHP 8.5's deprecated curl_close().
            unset($handle);
        }
    }
}
