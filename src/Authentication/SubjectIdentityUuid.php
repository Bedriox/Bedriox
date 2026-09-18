<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

use InvalidArgumentException;

/** Maps a verified auth-service subject to a stable Bedrock wire UUID. */
final class SubjectIdentityUuid
{
    private const string DNS_NAMESPACE = '6ba7b8109dad11d180b400c04fd430c8';

    public static function fromSubject(string $subject): string
    {
        if ($subject === '' || strlen($subject) > 256 || preg_match('//u', $subject) !== 1) {
            throw new InvalidArgumentException('Authenticated subject is not a bounded UTF-8 string.');
        }
        $namespace = hex2bin(self::DNS_NAMESPACE);
        if ($namespace === false) {
            throw new \LogicException('UUID namespace is invalid.');
        }
        $bytes = substr(hash('sha1', $namespace . 'bedriox:full:' . $subject, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
