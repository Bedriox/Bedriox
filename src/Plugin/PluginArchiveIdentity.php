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

namespace Bedriox\Server\Plugin;

use InvalidArgumentException;

/** Immutable identity captured after complete PHAR admission. */
final readonly class PluginArchiveIdentity
{
    public function __construct(
        public string $path,
        public string $owner,
        public string $version,
        public string $sha256,
        public string $signatureType,
        public string $signatureHash,
    ) {
        if ($this->path === '' || strlen($this->path) > 4096 || str_contains($this->path, "\0")
            || $this->owner === '' || strlen($this->owner) > 128
            || $this->version === '' || strlen($this->version) > 128
            || preg_match('/^[a-f0-9]{64}$/D', $this->sha256) !== 1
            || !in_array($this->signatureType, ['SHA-256', 'SHA-512', 'OpenSSL', 'OpenSSL_SHA256', 'OpenSSL_SHA512'], true)
            || $this->signatureHash === '' || strlen($this->signatureHash) > 16_384
            || ctype_xdigit($this->signatureHash) === false) {
            throw new InvalidArgumentException('Plugin archive identity is invalid.');
        }
    }
}
