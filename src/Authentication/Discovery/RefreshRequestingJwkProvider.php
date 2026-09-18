<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication\Discovery;

use Bedriox\Server\Authentication\JwkProvider;

interface RefreshRequestingJwkProvider extends JwkProvider
{
    public function requestRefreshFor(KeyId $keyId): bool;
}
