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

namespace Bedriox\Server\Authentication;

use Bedriox\Protocol\Security\Base64Url;
use Bedriox\Protocol\Security\BoundedJson;
use Throwable;

/** @internal */
final readonly class BoundedFullTokenParser
{
    public function __construct(private FullAuthenticationLimits $limits) {}

    public function parse(#[\SensitiveParameter] string $compact): ParsedFullToken
    {
        if (strlen($compact) > $this->limits->maximumTokenBytes) {
            throw new AuthenticationException('FULL token exceeds its byte limit.');
        }
        $segments = explode('.', $compact);
        if (count($segments) !== 3 || in_array('', $segments, true)) {
            throw new AuthenticationException('FULL token is not a compact JWS.');
        }
        try {
            $header = BoundedJson::decodeObject(
                Base64Url::decode($segments[0], $this->limits->maximumHeaderBytes),
                $this->limits->maximumHeaderBytes,
                $this->limits->maximumJsonDepth,
            );
            $claims = BoundedJson::decodeObject(
                Base64Url::decode($segments[1], $this->limits->maximumPayloadBytes),
                $this->limits->maximumPayloadBytes,
                $this->limits->maximumJsonDepth,
            );
            $signature = Base64Url::decode($segments[2], $this->limits->maximumSignatureBytes);
        } catch (Throwable) {
            throw new AuthenticationException('FULL token encoding is invalid.');
        }
        if (($header['alg'] ?? null) !== 'RS256' || array_key_exists('crit', $header)
            || array_key_exists('b64', $header)) {
            throw new AuthenticationException('FULL token JOSE header is not supported.');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || !self::boundedAsciiIdentifier($kid, $this->limits->maximumKidBytes)) {
            throw new AuthenticationException('FULL token kid is invalid.');
        }
        return new ParsedFullToken($kid, $segments[0] . '.' . $segments[1], $signature, $claims);
    }

    private static function boundedAsciiIdentifier(string $value, int $maximumBytes): bool
    {
        return $value !== '' && strlen($value) <= $maximumBytes
            && preg_match('/\A[A-Za-z0-9._~-]+\z/D', $value) === 1;
    }
}
