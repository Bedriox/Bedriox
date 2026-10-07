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

namespace Bedriox\Server\Entity\Leash;

use Bedriox\Api\Entity\Value\LeashHolderType;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Vanilla\Misc\LeashKnotEntity;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerRegistry;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;

/**
 * Resolves the two identity domains which can truthfully back Bedrock leash-holder actor metadata.
 * Fence attachment requires a real leash-knot actor and is intentionally not synthesized here.
 */
final readonly class LeashAttachmentRegistry
{
    public const float MAXIMUM_ATTACHMENT_DISTANCE = 12.0;
    public const float MAXIMUM_FENCE_TRANSFER_DISTANCE = 7.0;
    private const int MAXIMUM_ENTITY_CHAIN_DEPTH = 16;
    private const int MAXIMUM_ATTACHMENTS_PER_HOLDER = 64;

    public function __construct(
        private PlayerRegistry $players,
        private EntityRegistry $entities,
    ) {}

    public function attachToPlayer(BreedableAnimalEntity $entity, Player $holder): bool
    {
        if (!$holder->vitals->isAlive() || $holder->worldName() !== $entity->getWorldName()
            || $holder->movement->position->distanceTo($entity->internalPosition()) > self::MAXIMUM_ATTACHMENT_DISTANCE) {
            return false;
        }
        $entity->setLeashHolder(
            $holder->identity->uuid,
            $holder->runtimeActorId,
            LeashHolderType::PLAYER,
        );

        return true;
    }

    public function attachToEntity(BreedableAnimalEntity $entity, AbstractEntity $holder): bool
    {
        if ($holder === $entity || $holder->isRemoved() || !$this->isAlive($holder)
            || $holder->getWorldName() !== $entity->getWorldName()
            || $holder->internalPosition()->distanceTo($entity->internalPosition()) > self::MAXIMUM_ATTACHMENT_DISTANCE
            || $this->wouldCreateCycle($entity, $holder)) {
            return false;
        }
        $entity->setLeashHolder(
            $holder->getUniqueId(),
            $holder->getRuntimeId(),
            LeashHolderType::ENTITY,
        );

        return true;
    }

    public function resolve(BreedableAnimalEntity $entity): ?ResolvedLeashHolder
    {
        $holder = $this->knownHolder($entity);
        if ($holder === null || ($holder instanceof Player && !$holder->vitals->isAlive())
            || ($holder instanceof AbstractEntity && !$this->isAlive($holder))) {
            return null;
        }

        if ($holder instanceof Player) {
            return new ResolvedLeashHolder(
                $holder,
                $holder->runtimeActorId,
                $holder->worldName(),
                $holder->movement->position,
            );
        }

        return new ResolvedLeashHolder(
            $holder,
            $holder->getRuntimeId(),
            $holder->getWorldName(),
            $holder->internalPosition(),
        );
    }

    public function knownHolder(BreedableAnimalEntity $entity): Player|AbstractEntity|null
    {
        $uniqueId = $entity->getLeashHolderUniqueId();
        $type = $entity->getLeashHolderType();
        if ($uniqueId === null || $type === null) {
            return null;
        }
        if ($type === LeashHolderType::PLAYER) {
            return $this->players->playerByIdentity($uniqueId);
        }
        $holder = $this->entities->getByUniqueId($uniqueId);

        return $holder === $entity || $holder?->isRemoved() === true ? null : $holder;
    }

    public function refreshRuntimeIdentity(BreedableAnimalEntity $entity, ResolvedLeashHolder $holder): void
    {
        if ($entity->getLeashHolderRuntimeId() !== $holder->runtimeId) {
            $entity->setLeashHolder(
                $entity->getLeashHolderUniqueId(),
                $holder->runtimeId,
                $entity->getLeashHolderType(),
            );
        }
    }

    public function detach(BreedableAnimalEntity $entity): bool
    {
        if (!$entity->isLeashed()) {
            return false;
        }
        $entity->setLeashHolder(null, null, null);

        return true;
    }

    /** @return list<BreedableAnimalEntity> */
    public function attachmentsToEntity(AbstractEntity $holder): array
    {
        $attachments = [];
        foreach ($this->entities->all() as $candidate) {
            if (!$candidate instanceof BreedableAnimalEntity
                || $candidate->getLeashHolderType() !== LeashHolderType::ENTITY
                || $candidate->getLeashHolderUniqueId() !== $holder->getUniqueId()) {
                continue;
            }
            $attachments[] = $candidate;
            if (count($attachments) >= self::MAXIMUM_ATTACHMENTS_PER_HOLDER) {
                break;
            }
        }

        return $attachments;
    }

    /** @return list<BreedableAnimalEntity> */
    public function attachmentsToPlayer(Player $holder): array
    {
        $attachments = [];
        foreach ($this->entities->all() as $candidate) {
            if (!$candidate instanceof BreedableAnimalEntity
                || $candidate->getLeashHolderType() !== LeashHolderType::PLAYER
                || $candidate->getLeashHolderUniqueId() !== $holder->identity->uuid) {
                continue;
            }
            $attachments[] = $candidate;
            if (count($attachments) >= self::MAXIMUM_ATTACHMENTS_PER_HOLDER) {
                break;
            }
        }

        return $attachments;
    }

    /** @return list<BreedableAnimalEntity> */
    public function transferableFromPlayer(Player $holder, Position $anchor): array
    {
        $attachments = [];
        foreach ($this->entities->all() as $candidate) {
            if (!$candidate instanceof BreedableAnimalEntity
                || $candidate->getWorldName() !== $holder->worldName()
                || $candidate->internalPosition()->distanceTo($anchor) > self::MAXIMUM_FENCE_TRANSFER_DISTANCE
                || $candidate->getLeashHolderType() !== LeashHolderType::PLAYER
                || $candidate->getLeashHolderUniqueId() !== $holder->identity->uuid) {
                continue;
            }
            $attachments[] = $candidate;
            if (count($attachments) >= self::MAXIMUM_ATTACHMENTS_PER_HOLDER) {
                break;
            }
        }

        return $attachments;
    }

    public function knotAt(string $worldName, BlockPosition $fence): ?LeashKnotEntity
    {
        foreach ($this->entities->nearby(
            $worldName,
            LeashKnotEntity::anchorPosition($fence),
            0.25,
            2,
            type: VanillaEntityType::LEASH_KNOT,
        ) as $candidate) {
            if ($candidate instanceof LeashKnotEntity && $candidate->fencePosition() == $fence) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<LeashKnotEntity> */
    public function knots(): array
    {
        $knots = [];
        foreach ($this->entities->all() as $entity) {
            if ($entity instanceof LeashKnotEntity) {
                $knots[] = $entity;
            }
        }

        return $knots;
    }

    private function wouldCreateCycle(BreedableAnimalEntity $entity, AbstractEntity $holder): bool
    {
        $visited = [$entity->getUniqueId() => true];
        $current = $holder;
        for ($depth = 0; $depth < self::MAXIMUM_ENTITY_CHAIN_DEPTH; ++$depth) {
            $uniqueId = $current->getUniqueId();
            if (isset($visited[$uniqueId])) {
                return true;
            }
            $visited[$uniqueId] = true;
            if (!$current instanceof BreedableAnimalEntity
                || $current->getLeashHolderType() !== LeashHolderType::ENTITY
                || $current->getLeashHolderUniqueId() === null) {
                return false;
            }
            $next = $this->entities->getByUniqueId($current->getLeashHolderUniqueId());
            if ($next === null) {
                return false;
            }
            $current = $next;
        }

        return true;
    }

    private function isAlive(AbstractEntity $entity): bool
    {
        return !$entity instanceof AbstractLivingEntity || $entity->isAlive();
    }
}
