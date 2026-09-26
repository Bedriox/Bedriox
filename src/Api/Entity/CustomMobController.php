<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

/**
 * Bounded one-tick intents for the authoritative custom-mob runtime.
 * Intents are applied only when the current plugin callback completes successfully.
 *
 * @deprecated Use MobController. This compatibility name remains valid for
 *             existing CustomMobBehavior implementations.
 */
interface CustomMobController extends MobController {}
