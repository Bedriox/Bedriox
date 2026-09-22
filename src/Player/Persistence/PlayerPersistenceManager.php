<?php

declare(strict_types=1);

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Closure;
use Throwable;

/** Resolves authenticated profiles and retains failed authoritative saves for bounded retry. */
final class PlayerPersistenceManager
{
    /** @var array<string, array{player: Player, profile: PlayerBootstrap, revision: int}> */
    private array $pending = [];

    /** @param Closure(): int|null $clock */
    public function __construct(
        private readonly PlayerDataStore $store,
        private readonly string $worldName,
        private readonly Position $defaultSpawn,
        private readonly FixedFlatBlockPalette $palette,
        ?Closure $clock = null,
        private readonly string $defaultGamemode = 'survival',
        private readonly ?ItemCatalog $itemCatalog = null,
    ) {
        GameMode::from($this->defaultGamemode);
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function load(AuthenticatedLogin $login): PlayerBootstrap
    {
        $uuid = strtolower($login->identity);
        $saved = $this->store->load($uuid);
        $now = ($this->clock)();
        if ($saved === null) {
            return new PlayerBootstrap(
                new PlayerIdentity($uuid, $login->displayName, $login->xuid),
                $this->worldName,
                $this->defaultSpawn,
                0.0,
                0.0,
                PlayerInventory::starter($this->palette, $this->itemCatalog)->exportState(),
                $now,
                $now,
                $this->defaultGamemode,
            );
        }

        $sameWorld = $saved->worldName === $this->worldName;
        $restoreLocation = $sameWorld && $saved->health > 0.0;

        return new PlayerBootstrap(
            new PlayerIdentity($uuid, $login->displayName, $login->xuid),
            $this->worldName,
            $restoreLocation ? $saved->position : $this->defaultSpawn,
            $restoreLocation ? $saved->yaw : 0.0,
            $restoreLocation ? $saved->pitch : 0.0,
            $saved->inventory,
            $saved->firstPlayedAt,
            $now,
            $saved->gamemode,
            $saved->health > 0.0 ? $saved->health : \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH,
        );
    }

    public function snapshot(Player $player): PlayerBootstrap
    {
        return new PlayerBootstrap(
            $player->identity,
            $player->worldName,
            $player->movement->position,
            $player->movement->yaw,
            $player->movement->pitch,
            $player->inventory->exportState(),
            $player->firstPlayedAt,
            ($this->clock)(),
            $player->gamemode,
            $player->vitals->health,
        );
    }

    public function save(Player $player): bool
    {
        $revision = $player->stateRevision();
        $profile = $this->snapshot($player);
        try {
            $this->store->save($profile);
            $player->acknowledgeSaved($revision);
            unset($this->pending[$player->identity->uuid]);

            return true;
        } catch (Throwable) {
            $this->pending[$player->identity->uuid] = compact('player', 'profile', 'revision');

            return false;
        }
    }

    /** @phpstan-impure */
    public function retryPending(int $budget): int
    {
        $saved = 0;
        foreach (array_keys($this->pending) as $uuid) {
            if ($saved >= $budget) {
                break;
            }
            $entry = $this->pending[$uuid];
            try {
                $this->store->save($entry['profile']);
                $entry['player']->acknowledgeSaved($entry['revision']);
                unset($this->pending[$uuid]);
                ++$saved;
            } catch (Throwable) {
                // The last authoritative snapshot remains queued for a later bounded retry.
            }
        }

        return $saved;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }
}
