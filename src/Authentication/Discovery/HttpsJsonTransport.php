<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication\Discovery;

interface HttpsJsonTransport
{
    public function get(string $url, int $maximumResponseBytes): string;
}
