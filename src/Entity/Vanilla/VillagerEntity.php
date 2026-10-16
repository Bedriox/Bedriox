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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerActivity;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerType;
use Bedriox\Api\Entity\Vanilla\Villager;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\ExclusiveAiActivity;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\Spawn\SpawnVariantAware;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Gameplay\Trading\DefaultVillagerTradeCatalog;
use Bedriox\Server\Gameplay\Trading\VillagerScheduleRules;
use Bedriox\Server\Gameplay\Trading\VillagerTradeCodec;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use JsonException;

final class VillagerEntity extends AbstractMobEntity implements ExclusiveAiActivity, IntrinsicEntityPersistence, SpawnVariantAware, Villager
{
    private VillagerTradeList $trades;

    /** The one player currently owning this villager's trade window; never persisted. */
    private ?int $activeTraderRuntimeId = null;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        ?AiBehaviorDefinition $behavior = null,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        private bool $baby = false,
        private VillagerProfession $profession = VillagerProfession::UNEMPLOYED,
        private VillagerType $villagerType = VillagerType::PLAINS,
        private VillagerLevel $villagerLevel = VillagerLevel::NOVICE,
        private int $villagerExperience = 0,
        ?VillagerTradeList $trades = null,
        private int $restocksToday = 0,
        private int $lastRestockDay = -1,
        private ?BlockPosition $workstation = null,
        private ?BlockPosition $bed = null,
        private VillagerActivity $activity = VillagerActivity::IDLE,
        private bool $sleeping = false,
    ) {
        self::validateState($villagerExperience, $restocksToday, $lastRestockDay);
        $this->trades = $trades ?? new VillagerTradeList([]);
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::villager(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::villager(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
    }

    public function isBaby(): bool
    {
        return $this->baby;
    }

    public function getProfession(): VillagerProfession
    {
        return $this->profession;
    }

    public function getVillagerType(): VillagerType
    {
        return $this->villagerType;
    }

    public function getVillagerLevel(): VillagerLevel
    {
        return $this->villagerLevel;
    }

    public function getVillagerExperience(): int
    {
        return $this->villagerExperience;
    }

    public function getTrades(): VillagerTradeList
    {
        return $this->trades;
    }

    public function getRestocksToday(): int
    {
        return $this->restocksToday;
    }

    public function getLastRestockDay(): int
    {
        return $this->lastRestockDay;
    }

    public function getWorkstation(): ?BlockPosition
    {
        return $this->workstation;
    }

    public function getActiveTraderRuntimeId(): ?int
    {
        return $this->activeTraderRuntimeId;
    }

    public function getBed(): ?BlockPosition
    {
        return $this->bed;
    }

    public function getBedPosition(): ?ApiBlockPosition
    {
        return $this->bed === null
            ? null
            : new ApiBlockPosition($this->bed->x, $this->bed->y, $this->bed->z);
    }

    public function getWorkstationPosition(): ?ApiBlockPosition
    {
        return $this->workstation === null
            ? null
            : new ApiBlockPosition($this->workstation->x, $this->workstation->y, $this->workstation->z);
    }

    public function getActivity(): VillagerActivity
    {
        return $this->activity;
    }

    public function isSleeping(): bool
    {
        return $this->sleeping;
    }

    public function hasExclusiveAiActivity(): bool
    {
        return $this->activeTraderRuntimeId !== null || $this->sleeping;
    }

    /** @internal Claimed by the authoritative container lifecycle. */
    public function beginTrading(int $playerRuntimeId): bool
    {
        if ($playerRuntimeId < 1) {
            throw new InvalidArgumentException('Trader runtime ID must be positive.');
        }
        if ($this->activeTraderRuntimeId !== null && $this->activeTraderRuntimeId !== $playerRuntimeId) {
            return false;
        }
        if ($this->activeTraderRuntimeId === null) {
            $this->activeTraderRuntimeId = $playerRuntimeId;
            $this->clearNavigation();
            $this->applyMotion(new EntityMotion());
            $this->markPresentationChanged();
        }

        return true;
    }

    /** @internal Released by the authoritative container lifecycle. */
    public function endTrading(int $playerRuntimeId): void
    {
        if ($this->activeTraderRuntimeId === $playerRuntimeId) {
            $this->activeTraderRuntimeId = null;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative settlement mutation. */
    public function assignWorkstation(?BlockPosition $workstation): void
    {
        if ($this->workstation != $workstation) {
            $this->workstation = $workstation;
            $this->markChanged();
        }
    }

    /** @internal Authoritative settlement mutation. */
    public function assignBed(?BlockPosition $bed): void
    {
        if ($this->bed != $bed) {
            $this->bed = $bed;
            if ($bed === null) {
                $this->setSleeping(false);
            }
            $this->markChanged();
        }
    }

    /** @internal Authoritative schedule mutation. */
    public function setActivity(VillagerActivity $activity): void
    {
        if ($this->activity !== $activity) {
            $this->activity = $activity;
            $this->markChanged();
        }
    }

    /** @internal Authoritative schedule mutation. */
    public function setSleeping(bool $sleeping): void
    {
        if ($sleeping && $this->bed === null) {
            throw new InvalidArgumentException('A villager cannot sleep without an assigned bed.');
        }
        if ($this->sleeping !== $sleeping) {
            $this->sleeping = $sleeping;
            if ($sleeping) {
                $this->clearNavigation();
                $this->applyMotion(new EntityMotion());
            }
            $this->markPresentationChanged();
        }
    }

    public function applySpawnVariant(int|string $variant): void
    {
        if (!is_string($variant) || preg_match('/^([a-z_]+):([a-z_]+)$/D', $variant, $matches) !== 1) {
            throw new InvalidArgumentException('Villager spawn variant is malformed.');
        }
        $profession = self::professionFromName($matches[1]);
        $type = self::typeFromName($matches[2]);
        if ($profession === null || $type === null) {
            throw new InvalidArgumentException('Villager spawn variant is unsupported.');
        }
        $this->profession = $profession;
        $this->villagerType = $type;
        $this->trades = (new DefaultVillagerTradeCatalog())->generate($profession, $this->tradeSeed());
        $this->markPresentationChanged();
    }

    /** @internal Authoritative settlement mutation. */
    public function setBaby(bool $baby): void
    {
        if ($this->baby !== $baby) {
            $this->baby = $baby;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative settlement mutation. */
    public function setProfession(VillagerProfession $profession, ?VillagerTradeList $trades = null): void
    {
        if ($this->profession !== $profession) {
            if ($this->trades->generation === 2_147_483_647) {
                throw new InvalidArgumentException('Villager trade generation is exhausted.');
            }
            $this->profession = $profession;
            $this->villagerLevel = VillagerLevel::NOVICE;
            $this->villagerExperience = 0;
            $selectedTrades = $trades
                ?? (new DefaultVillagerTradeCatalog())->generate($profession, $this->tradeSeed());
            $this->trades = new VillagerTradeList($selectedTrades->offers, $this->trades->generation + 1);
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative settlement mutation. */
    public function replaceTrades(VillagerTradeList $trades): void
    {
        $this->trades = $trades;
        $this->markPresentationChanged();
    }

    /** @internal Authoritative trade mutation. */
    public function setTradeProgress(VillagerLevel $level, int $experience): void
    {
        self::validateState($experience, $this->restocksToday, $this->lastRestockDay);
        if ($level !== $this->villagerLevel || $experience !== $this->villagerExperience) {
            $this->villagerLevel = $level;
            $this->villagerExperience = $experience;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative schedule mutation. */
    public function recordRestock(int $worldDay): void
    {
        if ($worldDay < 0 || $worldDay > 2_147_483_647) {
            throw new InvalidArgumentException('Villager restock day is outside its supported range.');
        }
        $restocksToday = $worldDay === $this->lastRestockDay ? $this->restocksToday + 1 : 1;
        self::validateState($this->villagerExperience, $restocksToday, $worldDay);
        $this->restocksToday = $restocksToday;
        $this->lastRestockDay = $worldDay;
        $this->markChanged();
    }

    /** @internal Authoritative schedule mutation. */
    public function restock(int $worldDay): bool
    {
        if (!$this->canRestock($worldDay)) {
            return false;
        }
        $this->trades = VillagerScheduleRules::restocked($this->trades);
        $this->recordRestock($worldDay);
        $this->markPresentationChanged();

        return true;
    }

    public function canRestock(int $worldDay): bool
    {
        if ($worldDay < 0 || $worldDay > 2_147_483_647) {
            throw new InvalidArgumentException('Villager restock day is outside its supported range.');
        }

        $hasUsedOffer = false;
        foreach ($this->trades->offers as $offer) {
            if ($offer->uses > 0) {
                $hasUsedOffer = true;
                break;
            }
        }

        return !$this->baby
            && $this->profession->canTrade()
            && $this->workstation !== null
            && $hasUsedOffer
            && ($this->lastRestockDay !== $worldDay || $this->restocksToday < 2);
    }

    public function persistenceVariant(): int
    {
        return $this->profession->value;
    }

    public function persistenceSchemaVersion(): int
    {
        return 2;
    }

    public function persistenceData(): string
    {
        return json_encode([
            'baby' => $this->baby,
            'type' => $this->villagerType->value,
            'level' => $this->villagerLevel->value,
            'experience' => $this->villagerExperience,
            'restocksToday' => $this->restocksToday,
            'lastRestockDay' => $this->lastRestockDay,
            'workstation' => $this->workstation === null ? null : [
                'x' => $this->workstation->x,
                'y' => $this->workstation->y,
                'z' => $this->workstation->z,
            ],
            'bed' => $this->bed === null ? null : [
                'x' => $this->bed->x,
                'y' => $this->bed->y,
                'z' => $this->bed->z,
            ],
            'sleeping' => $this->sleeping,
            'trades' => VillagerTradeCodec::encode($this->trades),
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || !in_array($schemaVersion, [1, 2], true) || strlen($data) > 65_536) {
            throw new InvalidArgumentException('Persisted villager state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted villager state is malformed.', previous: $error);
        }
        $expectedKeys = $schemaVersion === 1
            ? ['baby', 'type', 'level', 'experience', 'restocksToday', 'lastRestockDay', 'workstation', 'trades']
            : ['baby', 'type', 'level', 'experience', 'restocksToday', 'lastRestockDay', 'workstation', 'bed', 'sleeping', 'trades'];
        if (!is_array($decoded) || array_keys($decoded) !== $expectedKeys
            || !is_bool($decoded['baby']) || !is_int($decoded['type']) || !is_int($decoded['level'])
            || !is_int($decoded['experience']) || !is_int($decoded['restocksToday'])
            || !is_int($decoded['lastRestockDay']) || !is_string($decoded['trades'])
            || ($decoded['workstation'] !== null && (!is_array($decoded['workstation'])
                || array_keys($decoded['workstation']) !== ['x', 'y', 'z']
                || !is_int($decoded['workstation']['x']) || !is_int($decoded['workstation']['y'])
                || !is_int($decoded['workstation']['z'])))
            || ($schemaVersion === 2 && (!is_bool($decoded['sleeping'])
                || ($decoded['bed'] !== null && (!is_array($decoded['bed'])
                    || array_keys($decoded['bed']) !== ['x', 'y', 'z']
                    || !is_int($decoded['bed']['x']) || !is_int($decoded['bed']['y'])
                    || !is_int($decoded['bed']['z'])))))) {
            throw new InvalidArgumentException('Persisted villager state is malformed.');
        }
        $profession = VillagerProfession::tryFrom($variant);
        $type = VillagerType::tryFrom($decoded['type']);
        $level = VillagerLevel::tryFrom($decoded['level']);
        if ($profession === null || $type === null || $level === null) {
            throw new InvalidArgumentException('Persisted villager identity is unsupported.');
        }
        self::validateState($decoded['experience'], $decoded['restocksToday'], $decoded['lastRestockDay']);
        $this->baby = $decoded['baby'];
        $this->profession = $profession;
        $this->villagerType = $type;
        $this->villagerLevel = $level;
        $this->villagerExperience = $decoded['experience'];
        $this->restocksToday = $decoded['restocksToday'];
        $this->lastRestockDay = $decoded['lastRestockDay'];
        $this->workstation = self::decodePosition($decoded['workstation'], 'workstation');
        $this->bed = $schemaVersion === 2 ? self::decodePosition($decoded['bed'], 'bed') : null;
        $this->sleeping = $schemaVersion === 2 && $decoded['sleeping'] && $this->bed !== null;
        $this->activity = $this->sleeping ? VillagerActivity::REST : VillagerActivity::IDLE;
        $persistedTrades = VillagerTradeCodec::decode($decoded['trades']);
        $this->trades = (new DefaultVillagerTradeCatalog())->upgradePersisted(
            $profession,
            $this->tradeSeed(),
            $persistedTrades,
        );
        if ($this->trades !== $persistedTrades) {
            $this->markChanged();
        }
    }

    private static function validateState(int $experience, int $restocksToday, int $lastRestockDay): void
    {
        if ($experience < 0 || $experience > VillagerLevel::MAXIMUM_EXPERIENCE
            || $restocksToday < 0 || $restocksToday > 2
            || $lastRestockDay < -1 || $lastRestockDay > 2_147_483_647) {
            throw new InvalidArgumentException('Villager state is outside its supported bounds.');
        }
    }

    private static function decodePosition(mixed $value, string $field): ?BlockPosition
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_keys($value) !== ['x', 'y', 'z']
            || !is_int($value['x']) || !is_int($value['y']) || !is_int($value['z'])) {
            throw new InvalidArgumentException('Persisted villager ' . $field . ' position is malformed.');
        }

        return new BlockPosition($value['x'], $value['y'], $value['z']);
    }

    private static function professionFromName(string $name): ?VillagerProfession
    {
        foreach (VillagerProfession::cases() as $profession) {
            if (strtolower($profession->name) === $name) {
                return $profession;
            }
        }

        return null;
    }

    private static function typeFromName(string $name): ?VillagerType
    {
        foreach (VillagerType::cases() as $type) {
            if (strtolower($type->name) === $name) {
                return $type;
            }
        }

        return null;
    }

    private function tradeSeed(): int
    {
        $seed = (int) hexdec(substr(hash('sha256', $this->getUniqueId()), 0, 8));
        if ($seed > 0x7fffffff) {
            $seed -= 0x100000000;
        }

        return $seed;
    }
}
