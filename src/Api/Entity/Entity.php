<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use Bedriox\Api\World\Position;

interface Entity
{
    public function getUniqueId(): string;

    public function getRuntimeId(): int;

    public function getType(): EntityType;

    public function getCategory(): EntityCategory;

    public function getPosition(): Position;

    public function getWorldName(): string;

    public function getYaw(): float;

    public function getPitch(): float;

    public function isOnGround(): bool;

    public function isPersistent(): bool;

    public function getController(): EntityController;
}
