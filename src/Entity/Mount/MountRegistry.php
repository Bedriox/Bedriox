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

namespace Bedriox\Server\Entity\Mount;

use Bedriox\Api\Entity\MountedPassenger;
use Bedriox\Api\Entity\Value\MountedPassengerKind;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Player\Player;

/** World-owned, bounded source of truth for every live vehicle relationship. */
final class MountRegistry
{
    public const int MAXIMUM_PASSENGERS_PER_VEHICLE = 4;

    /** @var array<string, MountLink> */
    private array $byPassenger = [];

    /** @var array<int, array<int, string>> vehicle runtime ID => seat ID => passenger key */
    private array $passengersByVehicle = [];

    public function mountPlayer(Player $player, AbstractEntity $vehicle, MountSeat $seat): ?MountLink
    {
        return $this->mount(
            self::playerKey($player->sessionId),
            new MountedPassenger(MountedPassengerKind::PLAYER, $player->identity->uuid, $player->runtimeActorId, $seat),
            $vehicle,
            $seat,
            playerSessionId: $player->sessionId,
        );
    }

    public function mountEntity(AbstractEntity $passenger, AbstractEntity $vehicle, MountSeat $seat): ?MountLink
    {
        if ($passenger === $vehicle || $this->wouldCreateCycle($passenger, $vehicle)) {
            return null;
        }

        return $this->mount(
            self::entityKey($passenger->getRuntimeId()),
            new MountedPassenger(MountedPassengerKind::ENTITY, $passenger->getUniqueId(), $passenger->getRuntimeId(), $seat),
            $vehicle,
            $seat,
            passengerEntity: $passenger,
        );
    }

    public function playerLink(string $sessionId): ?MountLink
    {
        return $this->byPassenger[self::playerKey($sessionId)] ?? null;
    }

    public function entityLink(int $runtimeId): ?MountLink
    {
        return $this->byPassenger[self::entityKey($runtimeId)] ?? null;
    }

    public function dismountPlayer(string $sessionId): ?MountLink
    {
        return $this->remove(self::playerKey($sessionId));
    }

    public function dismountEntity(int $runtimeId): ?MountLink
    {
        return $this->remove(self::entityKey($runtimeId));
    }

    /** @return list<MountLink> */
    public function dismountVehicle(int $runtimeId): array
    {
        $links = [];
        foreach ($this->passengersByVehicle[$runtimeId] ?? [] as $passengerKey) {
            $link = $this->remove($passengerKey);
            if ($link !== null) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /** @return list<MountLink> */
    public function linksForVehicle(int $runtimeId): array
    {
        $links = [];
        $seats = $this->passengersByVehicle[$runtimeId] ?? [];
        ksort($seats, SORT_NUMERIC);
        foreach ($seats as $passengerKey) {
            $link = $this->byPassenger[$passengerKey] ?? null;
            if ($link !== null) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /** @return list<MountedPassenger> */
    public function passengerViews(int $vehicleRuntimeId): array
    {
        return array_map(
            static fn(MountLink $link): MountedPassenger => $link->passenger,
            $this->linksForVehicle($vehicleRuntimeId),
        );
    }

    public function count(): int
    {
        return count($this->byPassenger);
    }

    /** @return list<MountLink> */
    public function links(): array
    {
        return array_values($this->byPassenger);
    }

    private function mount(
        string $passengerKey,
        MountedPassenger $passenger,
        AbstractEntity $vehicle,
        MountSeat $seat,
        ?AbstractEntity $passengerEntity = null,
        ?string $playerSessionId = null,
    ): ?MountLink {
        $vehicleId = $vehicle->getRuntimeId();
        if (isset($this->byPassenger[$passengerKey]) || isset($this->passengersByVehicle[$vehicleId][$seat->value])
            || count($this->passengersByVehicle[$vehicleId] ?? []) >= self::MAXIMUM_PASSENGERS_PER_VEHICLE
            || $vehicle->isRemoved()) {
            return null;
        }
        $link = new MountLink($passengerKey, $passenger, $vehicle, $seat, $passengerEntity, $playerSessionId);
        $this->byPassenger[$passengerKey] = $link;
        $this->passengersByVehicle[$vehicleId][$seat->value] = $passengerKey;

        return $link;
    }

    private function remove(string $passengerKey): ?MountLink
    {
        $link = $this->byPassenger[$passengerKey] ?? null;
        if ($link === null) {
            return null;
        }
        unset($this->byPassenger[$passengerKey]);
        $vehicleId = $link->vehicle->getRuntimeId();
        unset($this->passengersByVehicle[$vehicleId][$link->seat->value]);
        if (($this->passengersByVehicle[$vehicleId] ?? []) === []) {
            unset($this->passengersByVehicle[$vehicleId]);
        }

        return $link;
    }

    private function wouldCreateCycle(AbstractEntity $passenger, AbstractEntity $vehicle): bool
    {
        $cursor = $vehicle;
        for ($depth = 0; $depth <= self::MAXIMUM_PASSENGERS_PER_VEHICLE; ++$depth) {
            if ($cursor === $passenger) {
                return true;
            }
            $link = $this->entityLink($cursor->getRuntimeId());
            if ($link === null) {
                return false;
            }
            $cursor = $link->vehicle;
        }

        return true;
    }

    private static function playerKey(string $sessionId): string
    {
        return 'player:' . strtolower($sessionId);
    }

    private static function entityKey(int $runtimeId): string
    {
        return 'entity:' . $runtimeId;
    }
}
