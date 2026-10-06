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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Durable link ownership and bounded contact cooldowns for paired End gateways. */
final class EndGatewayRuntime
{
    public const int CONTACT_COOLDOWN_TICKS = 40;

    /** @var array<int, EndGatewayLink> */
    private array $links = [];

    /** @var array<string, int> */
    private array $cooldowns = [];

    public function __construct(private readonly ?EndGatewayStateRepository $repository = null)
    {
        foreach ($repository?->load() ?? [] as $link) {
            $this->links[$link->slot] = $link;
        }
    }

    /** @return list<EndGatewayLink> */
    public function links(): array
    {
        ksort($this->links);

        return array_values($this->links);
    }

    public function activate(int $slot): EndGatewayLink
    {
        return $this->activateAt($slot, EndGatewayPlanner::inner($slot));
    }

    public function activateAt(int $slot, BlockPosition $inner): EndGatewayLink
    {
        if ($slot < 0 || $slot >= EndGatewayPlanner::GATEWAY_COUNT) {
            throw new InvalidArgumentException('End gateway slot is outside the supported ring.');
        }
        $link = $this->links[$slot] ??= new EndGatewayLink(
            $slot,
            $inner,
            EndGatewayPlanner::outer($slot),
        );
        $this->repository?->save($this->links());

        return $link;
    }

    public function contact(string $actorKey, BlockPosition $gateway, int $tick): ?EndGatewayTransfer
    {
        if ($actorKey === '' || strlen($actorKey) > 96 || $tick < 0) {
            throw new InvalidArgumentException('End gateway contact identity or tick is invalid.');
        }
        if (($this->cooldowns[$actorKey] ?? 0) > $tick) {
            return null;
        }
        foreach ($this->links as $link) {
            $destination = $link->destinationFrom($gateway);
            if ($destination === null) {
                continue;
            }
            $cooldownUntil = $tick + self::CONTACT_COOLDOWN_TICKS;
            $this->cooldowns[$actorKey] = $cooldownUntil;
            $arrival = self::safeArrival($destination);

            return new EndGatewayTransfer(
                $arrival,
                $cooldownUntil,
            );
        }

        return null;
    }

    public function contactVolume(
        string $actorKey,
        Position $position,
        float $width,
        float $height,
        int $tick,
    ): ?EndGatewayTransfer {
        if (!is_finite($width) || !is_finite($height) || $width <= 0.0 || $width > 16.0
            || $height <= 0.0 || $height > 16.0) {
            throw new InvalidArgumentException('End gateway contact volume is invalid.');
        }
        $halfWidth = $width / 2.0;
        foreach ($this->links as $link) {
            foreach ([$link->inner, $link->outer] as $gateway) {
                if ($position->x + $halfWidth <= $gateway->x || $position->x - $halfWidth >= $gateway->x + 1.0
                    || $position->y + $height <= $gateway->y || $position->y >= $gateway->y + 1.0
                    || $position->z + $halfWidth <= $gateway->z || $position->z - $halfWidth >= $gateway->z + 1.0) {
                    continue;
                }

                return $this->contact($actorKey, $gateway, $tick);
            }
        }

        return null;
    }

    public function forgetActor(string $actorKey): void
    {
        unset($this->cooldowns[$actorKey]);
    }

    private static function safeArrival(BlockPosition $gateway): Position
    {
        if (abs($gateway->x) >= abs($gateway->z)) {
            $offsetX = $gateway->x < 0 ? -2 : 2;
            $offsetZ = 0;
        } else {
            $offsetX = 0;
            $offsetZ = $gateway->z < 0 ? -2 : 2;
        }

        return new Position($gateway->x + $offsetX + 0.5, $gateway->y + 1.0, $gateway->z + $offsetZ + 0.5);
    }
}
