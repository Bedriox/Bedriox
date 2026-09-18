<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication\Discovery;

final class EndpointPolicy
{
    /** @var array<string, true> */
    private const array HOSTS = [
        'authorization.franchise.minecraft-services.net' => true,
        'client.discovery.minecraft-services.net' => true,
    ];

    public static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null)
            || !isset(self::HOSTS[strtolower($parts['host'])]) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment']) || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false) {
            throw new DiscoveryException('Discovery endpoint is not allowed.');
        }
    }
}
