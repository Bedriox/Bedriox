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

namespace Bedriox\Server\Observability\Profiling;

use InvalidArgumentException;
use JsonException;

final class TimingsUploadTaskCodec
{
    public const int MAXIMUM_REQUEST_BYTES = 2_900_000;
    public const int MAXIMUM_RESPONSE_BYTES = 8_192;

    public function encodeRequest(string $endpoint, string $compressedReport): string
    {
        if (strlen($compressedReport) > 2_097_152 || !$this->allowedEndpoint($endpoint)) {
            throw new InvalidArgumentException('Timing upload request exceeds its bounds.');
        }
        return json_encode([
            'endpoint' => $endpoint,
            'report' => base64_encode($compressedReport),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{endpoint: string, report: string} */
    public function decodeRequest(string $payload): array
    {
        if (strlen($payload) > self::MAXIMUM_REQUEST_BYTES) {
            throw new InvalidArgumentException('Timing upload request is too large.');
        }
        try {
            $value = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('Timing upload request is invalid.', previous: $failure);
        }
        if (!is_array($value) || !is_string($value['endpoint'] ?? null) || !is_string($value['report'] ?? null)
            || !$this->allowedEndpoint($value['endpoint'])) {
            throw new InvalidArgumentException('Timing upload request is invalid.');
        }
        $report = base64_decode($value['report'], true);
        if (!is_string($report) || strlen($report) > 2_097_152) {
            throw new InvalidArgumentException('Compressed timing report is invalid.');
        }
        return ['endpoint' => $value['endpoint'], 'report' => $report];
    }

    public function encodeResponse(string $id, string $url): string
    {
        if (!$this->validReportIdentity($id, $url)) {
            throw new InvalidArgumentException('Timing report response is invalid.');
        }
        return json_encode(['id' => $id, 'view_url' => $url], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{id: string, view_url: string} */
    public function decodeResponse(string $payload): array
    {
        if (strlen($payload) > self::MAXIMUM_RESPONSE_BYTES) {
            throw new InvalidArgumentException('Timing upload response is too large.');
        }
        try {
            $value = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('Timing upload response is invalid.', previous: $failure);
        }
        $id = is_array($value) ? ($value['id'] ?? null) : null;
        $url = is_array($value) ? ($value['view_url'] ?? null) : null;
        if (!is_string($id) || !is_string($url) || !$this->validReportIdentity($id, $url)) {
            throw new InvalidArgumentException('Timing upload response is invalid.');
        }
        return ['id' => $id, 'view_url' => $url];
    }

    private function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if ($parts['scheme'] === 'https' && strtolower($parts['host']) === 'timings.bedriox.com'
            && !isset($parts['port']) && $parts['path'] === '/api/v1/reports') {
            return true;
        }
        return $parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', '::1', 'localhost'], true)
            && $parts['path'] === '/api/v1/reports';
    }

    private function validReportIdentity(string $id, string $url): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1 || strlen($url) > 2_048
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || $parts['path'] !== '/r/' . $id) {
            return false;
        }
        if ($parts['scheme'] === 'https' && strtolower($parts['host']) === 'timings.bedriox.com'
            && !isset($parts['port'])) {
            return true;
        }
        return $parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', '::1', 'localhost'], true);
    }
}
