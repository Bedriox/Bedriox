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

use Bedriox\Api\Entity\Vanilla\Sniffer;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\ExclusiveAiActivity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class SnifferEntity extends LandBreedableAnimalEntity implements ExclusiveAiActivity, Sniffer
{
    private const int MAXIMUM_REMEMBERED_DIG_SITES = 20;

    /** @var array<string, true> Oldest entry first. */
    private array $rememberedDigSites = [];

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
        bool $baby = false,
        private bool $digging = false,
        private int $diggingTicks = 0,
        private int $sniffCooldownTicks = 0,
        string $rememberedDigSites = '',
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::sniffer(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('sniffer', ['minecraft:torchflower_seeds'], 0.09), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        if ($diggingTicks < 0 || $diggingTicks > 120
            || $sniffCooldownTicks < 0 || $sniffCooldownTicks > 12_000) {
            throw new InvalidArgumentException('Sniffer activity timers are outside their supported bounds.');
        }
        $this->diggingTicks = $diggingTicks;
        $this->sniffCooldownTicks = $sniffCooldownTicks;
        $this->restoreRememberedDigSites($rememberedDigSites);
    }

    public function isDigging(): bool
    {
        return $this->digging;
    }

    public function hasExclusiveAiActivity(): bool
    {
        return $this->digging;
    }

    public function getRememberedDigSiteCount(): int
    {
        return count($this->rememberedDigSites);
    }

    /** @internal Prevents repeatedly digging the same position. */
    public function hasDugAt(int $x, int $y, int $z): bool
    {
        return isset($this->rememberedDigSites[self::digSiteKey($x, $y, $z)]);
    }

    /** @internal Commits one successful dig site to the bounded oldest-first memory. */
    public function rememberDigSite(int $x, int $y, int $z): void
    {
        $key = self::digSiteKey($x, $y, $z);
        if (isset($this->rememberedDigSites[$key])) {
            return;
        }
        if (count($this->rememberedDigSites) === self::MAXIMUM_REMEMBERED_DIG_SITES) {
            array_shift($this->rememberedDigSites);
        }
        $this->rememberedDigSites[$key] = true;
        $this->markChanged();
    }

    /** @internal Authoritative species-state mutation. */
    public function setDigging(bool $digging): void
    {
        if ($this->digging !== $digging) {
            $this->digging = $digging;
            $this->markPresentationChanged();
        }
    }

    /** @internal Returns true exactly once when a dig completes. */
    public function advanceSniffing(int $ticks, bool $canDig): bool
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Sniffer activity advance is outside its supported bound.');
        }
        if ($this->diggingTicks > 0) {
            if (!$canDig) {
                $this->diggingTicks = 0;
                $this->sniffCooldownTicks = 200;
                $this->setDigging(false);
                $this->markChanged();
                return false;
            }
            $this->diggingTicks = max(0, $this->diggingTicks - $ticks);
            if ($this->diggingTicks === 0) {
                $this->setDigging(false);
                $this->sniffCooldownTicks = 9_600;
                $this->markChanged();
                return true;
            }
            $this->markChanged();
            return false;
        }
        $this->sniffCooldownTicks = max(0, $this->sniffCooldownTicks - $ticks);
        if (!$this->isBaby() && $canDig && $this->sniffCooldownTicks === 0) {
            $this->diggingTicks = 120;
            $this->setDigging(true);
            $motion = $this->getMotion();
            $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
        } elseif ($this->sniffCooldownTicks > 0) {
            $this->markChanged();
        }

        return false;
    }

    /** @internal Reopens a completed dig when its authoritative reward could not be admitted. */
    public function deferCompletedDig(): void
    {
        if ($this->digging || $this->diggingTicks !== 0) {
            throw new \LogicException('Only a completed sniffer dig may be deferred.');
        }
        $this->sniffCooldownTicks = 0;
        $this->diggingTicks = 20;
        $this->setDigging(true);
        $this->markChanged();
    }

    protected function babyScale(): float
    {
        return 0.45;
    }

    /** @return array{digging: bool, diggingTicks: int, sniffCooldownTicks: int, rememberedDigSites: string} */
    protected function speciesPersistenceData(): array
    {
        return [
            'digging' => $this->digging,
            'diggingTicks' => $this->diggingTicks,
            'sniffCooldownTicks' => $this->sniffCooldownTicks,
            'rememberedDigSites' => implode(';', array_keys($this->rememberedDigSites)),
        ];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if ($variant !== null || !is_bool($data['digging']) || !is_int($data['diggingTicks'])
            || !is_int($data['sniffCooldownTicks']) || $data['diggingTicks'] < 0 || $data['diggingTicks'] > 120
            || $data['sniffCooldownTicks'] < 0 || $data['sniffCooldownTicks'] > 12_000
            || !is_string($data['rememberedDigSites'])) {
            throw new InvalidArgumentException('Persisted sniffer state is malformed.');
        }
        $this->digging = $data['digging'];
        $this->diggingTicks = $data['diggingTicks'];
        $this->sniffCooldownTicks = $data['sniffCooldownTicks'];
        $this->restoreRememberedDigSites($data['rememberedDigSites']);
    }

    private function restoreRememberedDigSites(string $encoded): void
    {
        if (strlen($encoded) > 640) {
            throw new InvalidArgumentException('Persisted sniffer dig-site memory is malformed.');
        }
        $this->rememberedDigSites = [];
        if ($encoded === '') {
            return;
        }
        $entries = explode(';', $encoded);
        if (count($entries) > self::MAXIMUM_REMEMBERED_DIG_SITES) {
            throw new InvalidArgumentException('Persisted sniffer dig-site memory is malformed.');
        }
        foreach ($entries as $entry) {
            if (preg_match('/^-?\d+,-?\d+,-?\d+$/D', $entry) !== 1 || isset($this->rememberedDigSites[$entry])) {
                throw new InvalidArgumentException('Persisted sniffer dig-site memory is malformed.');
            }
            [$x, $y, $z] = array_map(static fn(string $value): int => (int) $value, explode(',', $entry));
            $key = self::digSiteKey($x, $y, $z);
            if ($key !== $entry) {
                throw new InvalidArgumentException('Persisted sniffer dig-site memory is malformed.');
            }
            $this->rememberedDigSites[$key] = true;
        }
    }

    private static function digSiteKey(int $x, int $y, int $z): string
    {
        if (abs($x) > 30_000_000 || abs($z) > 30_000_000 || $y < -2_048 || $y > 2_048) {
            throw new InvalidArgumentException('Sniffer dig site is outside world bounds.');
        }

        return $x . ',' . $y . ',' . $z;
    }
}
