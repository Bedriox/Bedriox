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

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\ClientCacheStatusPacket;
use Bedriox\Protocol\Packet\CompressionAlgorithm;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Packet\NetworkSettingsPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PlayStatus;
use Bedriox\Protocol\Packet\PlayStatusPacket;
use Bedriox\Protocol\Packet\RequestNetworkSettingsPacket;
use Bedriox\Protocol\Packet\ResourcePackChunkDataPacket;
use Bedriox\Protocol\Packet\ResourcePackChunkRequestPacket;
use Bedriox\Protocol\Packet\ResourcePackClientResponsePacket;
use Bedriox\Protocol\Packet\ResourcePackDataInfoPacket;
use Bedriox\Protocol\Packet\ResourcePackInfoEntry;
use Bedriox\Protocol\Packet\ResourcePackResponseStatus;
use Bedriox\Protocol\Packet\ResourcePacksInfoPacket;
use Bedriox\Protocol\Packet\ResourcePackStackEntry;
use Bedriox\Protocol\Packet\ResourcePackStackPacket;
use Bedriox\Protocol\Packet\ResourcePackType;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\ResourcePack\ResourcePackArchive;
use Bedriox\Server\ResourcePack\ResourcePackCatalog;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use SplQueue;
use Throwable;

/** Server-owned, transport-independent login state machine for the active compatible protocol family. */
final class LoginSession
{
    private const ABSOLUTE_TIMEOUT_NS = 60_000_000_000;
    private const PACK_TRANSFER_TIMEOUT_NS = 600_000_000_000;
    private const PACK_IDLE_TIMEOUT_NS = 30_000_000_000;

    /** @var SplQueue<LoginInput> */
    private SplQueue $input;

    /** @var SplQueue<LoginEffect> */
    private SplQueue $effects;

    private LoginState $state = LoginState::WAIT_NETWORK_REQUEST;
    private ?LoginFailureCode $failure = null;
    private int $queuedInputBytes = 0;
    private int $lastNow;
    private int $stateDeadline;
    private int $absoluteDeadline;
    private ?AuthenticatedLogin $authenticated = null;
    private bool $clientCacheStatusReceived = false;
    private ?int $observedProtocolVersion = null;
    private ?string $failureDetail = null;
    /** @var array<string, true> */
    private array $requestedPackMetadata = [];
    /** @var array<string, array<int, true>> */
    private array $sentPackChunks = [];
    /** @var SplQueue<array{ResourcePackArchive, int}> */
    private SplQueue $pendingPackChunks;
    private bool $packChunkInFlight = false;

    public function __construct(
        private readonly MonotonicClock $clock,
        private readonly LoginAuthenticator $authenticator,
        private readonly AuthenticationMode $authenticationMode = AuthenticationMode::FULL,
        private readonly LoginLimits $limits = new LoginLimits(),
        private readonly ResourcePackCatalog $resourcePacks = new ResourcePackCatalog(),
    ) {
        $this->input = new SplQueue();
        $this->effects = new SplQueue();
        $this->pendingPackChunks = new SplQueue();
        $this->lastNow = $clock->nowNanoseconds();
        $this->absoluteDeadline = $this->checkedAdd($this->lastNow, self::ABSOLUTE_TIMEOUT_NS);
        $this->stateDeadline = $this->checkedAdd($this->lastNow, 15_000_000_000);
    }

    public function state(): LoginState
    {
        return $this->state;
    }

    public function failure(): ?LoginFailureCode
    {
        return $this->failure;
    }

    public function observedProtocolVersion(): ?int
    {
        return $this->observedProtocolVersion;
    }

    public function failureDetail(): ?string
    {
        return $this->failureDetail;
    }

    public function securityPosture(): LoginSecurityPosture
    {
        return new LoginSecurityPosture($this->authenticationMode);
    }

    /**
     * Validate terminal state and deadlines before or after potentially expensive boundary work.
     *
     * @phpstan-impure Reads an external monotonic clock and may close the session.
     */
    public function preflight(): bool
    {
        if ($this->state === LoginState::CLOSED || $this->state === LoginState::LOGIN_READY) {
            return false;
        }
        return $this->checkTime();
    }

    public function enqueue(LoginInput $input): bool
    {
        if ($this->state === LoginState::CLOSED || $this->state === LoginState::LOGIN_READY) {
            return false;
        }
        $maximumQueuedPackets = $this->state === LoginState::WAIT_PACK_INFO_RESPONSE
            && $input->packet instanceof ResourcePackChunkRequestPacket
            ? $this->limits->maximumQueuedPackChunkRequests
            : $this->limits->maximumQueuedPackets;
        if ($input->wireBytes > $this->limits->maximumPacketBytes
            || $this->input->count() >= $maximumQueuedPackets
            || $input->wireBytes > $this->limits->maximumQueuedInputBytes - $this->queuedInputBytes) {
            $this->close(LoginFailureCode::INPUT_LIMIT);
            return false;
        }
        $this->input->enqueue($input);
        $this->queuedInputBytes += $input->wireBytes;
        return true;
    }

    /** Process a bounded slice of the packets present at entry, preventing callback-driven unbounded work. */
    public function tick(): void
    {
        if (!$this->preflight()) {
            return;
        }
        $remaining = min($this->input->count(), $this->limits->maximumInputsPerTick);
        while ($remaining-- > 0 && $this->state !== LoginState::CLOSED && $this->state !== LoginState::LOGIN_READY) {
            if (!$this->checkTime()) {
                break;
            }
            $input = $this->input->dequeue();
            $this->queuedInputBytes -= $input->wireBytes;
            if ($input->encrypted) {
                $this->close(LoginFailureCode::ENCRYPTION_STATE);
                break;
            }
            $this->handle($input->packet);
        }
    }

    /** @return list<LoginEffect> */
    public function drainEffects(): array
    {
        $drained = [];
        while (!$this->effects->isEmpty()) {
            $drained[] = $this->effects->dequeue();
        }
        return $drained;
    }

    public function close(LoginFailureCode $reason = LoginFailureCode::CLOSED_BY_SERVER): void
    {
        $this->failure ??= $reason;
        $this->state = LoginState::CLOSED;
        $this->authenticated = null;
        $this->input = new SplQueue();
        $this->effects = new SplQueue();
        $this->pendingPackChunks = new SplQueue();
        $this->packChunkInFlight = false;
        $this->queuedInputBytes = 0;
    }

    /** Release the next resource-pack chunk only after the transport confirms the previous one left its queue. */
    public function acknowledgePackChunk(): void
    {
        if ($this->state !== LoginState::WAIT_PACK_INFO_RESPONSE || !$this->packChunkInFlight) {
            return;
        }
        $this->packChunkInFlight = false;
        $this->emitNextPackChunk();
    }

    private function handle(Packet $packet): void
    {
        match ($this->state) {
            LoginState::WAIT_NETWORK_REQUEST => $this->handleNetworkRequest($packet),
            LoginState::WAIT_LOGIN => $this->handleLogin($packet),
            LoginState::WAIT_PACK_INFO_RESPONSE => $this->handlePackInfoResponse($packet),
            LoginState::WAIT_PACK_STACK_RESPONSE => $this->handlePackStackResponse($packet),
            LoginState::LOGIN_READY, LoginState::CLOSED => null,
        };
    }

    private function handleNetworkRequest(Packet $packet): void
    {
        if (!$packet instanceof RequestNetworkSettingsPacket) {
            $this->close(LoginFailureCode::UNEXPECTED_PACKET);
            return;
        }
        $this->observedProtocolVersion = $packet->protocolVersion;
        if ($packet->protocolVersion !== ProtocolVersion::CURRENT) {
            $this->close(LoginFailureCode::UNSUPPORTED_PROTOCOL);
            return;
        }
        if (!$this->emit(new SendPacketEffect(new NetworkSettingsPacket(
            NetworkCompressionPolicy::THRESHOLD_BYTES,
            CompressionAlgorithm::Zlib,
            false,
            0,
            0.0,
        ), false))) {
            return;
        }
        $this->transition(LoginState::WAIT_LOGIN, 20);
    }

    private function handleLogin(Packet $packet): void
    {
        if (!$packet instanceof LoginPacket) {
            $this->close(LoginFailureCode::UNEXPECTED_PACKET);
            return;
        }
        if ($packet->protocolVersion !== ProtocolVersion::CURRENT
            || $packet->protocolVersion !== $this->observedProtocolVersion) {
            $this->close(LoginFailureCode::UNSUPPORTED_PROTOCOL);
            return;
        }
        $authenticationType = $packet->authentication->type;
        $typeAllowed = match ($this->authenticationMode) {
            AuthenticationMode::FULL, AuthenticationMode::GATEWAY => $authenticationType === AuthenticationType::Full,
            AuthenticationMode::SELF_SIGNED => $authenticationType === AuthenticationType::Full
                || $authenticationType === AuthenticationType::SelfSigned,
        };
        if (!$typeAllowed) {
            $this->close(LoginFailureCode::AUTHENTICATION_MODE);
            return;
        }
        try {
            $login = $this->authenticator->authenticate($packet, $this->authenticationMode);
        } catch (Throwable $exception) {
            $this->failureDetail = 'authentication:' . $exception::class;
            $this->close(LoginFailureCode::AUTHENTICATION_FAILED);
            return;
        }
        if (!$this->preflight()) {
            return;
        }
        $this->authenticated = $login;
        if (!$this->canEmit(2)) {
            return;
        }
        $this->effects->enqueue(new SendPacketEffect(new PlayStatusPacket(PlayStatus::LoginSuccess), false));
        $entries = array_map(
            static fn(ResourcePackArchive $pack): ResourcePackInfoEntry => new ResourcePackInfoEntry(
                $pack->id,
                $pack->version,
                $pack->size,
                $pack->contentKey,
                $pack->subPackName,
                $pack->id,
                false,
                false,
                false,
                $pack->cdnUrl,
            ),
            $this->resourcePacks->stack,
        );
        $this->effects->enqueue(new SendPacketEffect(new ResourcePacksInfoPacket(
            $this->resourcePacks->required,
            false,
            false,
            false,
            '00000000-0000-0000-0000-000000000000',
            '',
            $entries,
        ), false));
        if (!$this->resourcePacks->isEmpty()) {
            $this->absoluteDeadline = $this->checkedAdd($this->lastNow, self::PACK_TRANSFER_TIMEOUT_NS);
        }
        $this->transitionNanoseconds(
            LoginState::WAIT_PACK_INFO_RESPONSE,
            $this->resourcePacks->isEmpty() ? 15_000_000_000 : self::PACK_IDLE_TIMEOUT_NS,
        );
    }

    private function handlePackInfoResponse(Packet $packet): void
    {
        if ($packet instanceof ClientCacheStatusPacket) {
            if ($this->clientCacheStatusReceived) {
                $this->close(LoginFailureCode::UNEXPECTED_PACKET);
                return;
            }
            $this->clientCacheStatusReceived = true;
            return;
        }
        if (!$packet instanceof ResourcePackClientResponsePacket) {
            if ($packet instanceof ResourcePackChunkRequestPacket) {
                $this->handlePackChunkRequest($packet);
                return;
            }
            $this->close(LoginFailureCode::UNEXPECTED_PACKET);
            return;
        }
        if ($packet->status === ResourcePackResponseStatus::Refused) {
            $this->close(LoginFailureCode::RESOURCE_PACK_PROBLEM);
            return;
        }
        if ($packet->status === ResourcePackResponseStatus::SendPacks) {
            $this->handlePackMetadataRequest($packet->packIds);
            return;
        }
        if ($packet->status !== ResourcePackResponseStatus::HaveAllPacks || $packet->packIds !== []
            || $this->packChunkInFlight || !$this->pendingPackChunks->isEmpty()) {
            $this->close(LoginFailureCode::INVALID_PACK_RESPONSE);
            return;
        }
        $protocolVersion = $this->observedProtocolVersion;
        if ($protocolVersion === null) {
            $this->close(LoginFailureCode::UNSUPPORTED_PROTOCOL);
            return;
        }
        $stack = array_map(
            static fn(ResourcePackArchive $pack): ResourcePackStackEntry => new ResourcePackStackEntry(
                $pack->id,
                $pack->version,
                $pack->subPackName,
            ),
            $this->resourcePacks->stack,
        );
        if (!$this->emit(new SendPacketEffect(new ResourcePackStackPacket(
            $this->resourcePacks->required,
            $stack,
            ProtocolVersion::gameVersion($protocolVersion),
            [],
            false,
            false,
        ), false))) {
            return;
        }
        $this->transitionNanoseconds(
            LoginState::WAIT_PACK_STACK_RESPONSE,
            $this->resourcePacks->isEmpty() ? 15_000_000_000 : self::PACK_IDLE_TIMEOUT_NS,
        );
    }

    private function handlePackStackResponse(Packet $packet): void
    {
        if (!$packet instanceof ResourcePackClientResponsePacket) {
            $this->close(LoginFailureCode::UNEXPECTED_PACKET);
            return;
        }
        if ($packet->status !== ResourcePackResponseStatus::Completed || $packet->packIds !== [] || $this->authenticated === null) {
            $this->close(LoginFailureCode::INVALID_PACK_RESPONSE);
            return;
        }
        $authenticated = $this->authenticated;
        if (!$this->emit(new LoginReadyEffect($authenticated))) {
            return;
        }
        $this->authenticated = null;
        $this->input = new SplQueue();
        $this->queuedInputBytes = 0;
        $this->state = LoginState::LOGIN_READY;
    }

    private function transition(LoginState $state, int $seconds): void
    {
        $this->transitionNanoseconds($state, $seconds * 1_000_000_000);
    }

    /** @param list<string> $ids */
    private function handlePackMetadataRequest(array $ids): void
    {
        if ($ids === [] || count($ids) > count($this->resourcePacks->stack)) {
            $this->close(LoginFailureCode::INVALID_PACK_RESPONSE);
            return;
        }
        foreach ($ids as $id) {
            $pack = $this->resourcePacks->pack($id);
            if ($pack === null || isset($this->requestedPackMetadata[$pack->id])) {
                $this->close(LoginFailureCode::RESOURCE_PACK_PROBLEM);
                return;
            }
            $this->requestedPackMetadata[$pack->id] = true;
            if (!$this->emit(new SendPacketEffect(new ResourcePackDataInfoPacket(
                $pack->id,
                $pack->version,
                ResourcePackArchive::CHUNK_BYTES,
                $pack->chunkCount(),
                $pack->size,
                $pack->sha256,
                false,
                ResourcePackType::Resources,
            ), false))) {
                return;
            }
        }
        $this->transitionNanoseconds(LoginState::WAIT_PACK_INFO_RESPONSE, self::PACK_IDLE_TIMEOUT_NS);
    }

    private function handlePackChunkRequest(ResourcePackChunkRequestPacket $packet): void
    {
        $pack = $this->resourcePacks->pack($packet->packId . '_' . $packet->packVersion);
        if ($pack === null || !isset($this->requestedPackMetadata[$pack->id])
            || $packet->chunkIndex < 0 || $packet->chunkIndex >= $pack->chunkCount()
            || isset($this->sentPackChunks[$pack->id][$packet->chunkIndex])) {
            $this->close(LoginFailureCode::RESOURCE_PACK_PROBLEM);
            return;
        }
        $this->sentPackChunks[$pack->id][$packet->chunkIndex] = true;
        $this->pendingPackChunks->enqueue([$pack, $packet->chunkIndex]);
        $this->emitNextPackChunk();
        $this->transitionNanoseconds(LoginState::WAIT_PACK_INFO_RESPONSE, self::PACK_IDLE_TIMEOUT_NS);
    }

    private function emitNextPackChunk(): void
    {
        if ($this->packChunkInFlight || $this->pendingPackChunks->isEmpty()) {
            return;
        }
        [$pack, $chunkIndex] = $this->pendingPackChunks->dequeue();
        try {
            $bytes = $pack->readChunk($chunkIndex);
        } catch (Throwable $failure) {
            $this->failureDetail = 'resource_pack:' . $failure::class;
            $this->close(LoginFailureCode::RESOURCE_PACK_PROBLEM);
            return;
        }
        $this->packChunkInFlight = true;
        $this->emit(new SendPacketEffect(new ResourcePackChunkDataPacket(
            $pack->id,
            $pack->version,
            $chunkIndex,
            $chunkIndex * ResourcePackArchive::CHUNK_BYTES,
            $bytes,
        ), false));
        $this->transitionNanoseconds(LoginState::WAIT_PACK_INFO_RESPONSE, self::PACK_IDLE_TIMEOUT_NS);
    }

    private function transitionNanoseconds(LoginState $state, int $duration): void
    {
        $this->state = $state;
        $this->stateDeadline = $this->checkedAdd($this->lastNow, $duration);
    }

    private function checkTime(): bool
    {
        $now = $this->clock->nowNanoseconds();
        if ($now < $this->lastNow) {
            $this->close(LoginFailureCode::CLOCK_FAILURE);
            return false;
        }
        $this->lastNow = $now;
        if ($now >= $this->stateDeadline || $now >= $this->absoluteDeadline) {
            $this->close(LoginFailureCode::TIMEOUT);
            return false;
        }
        return true;
    }

    private function emit(LoginEffect $effect): bool
    {
        if (!$this->canEmit(1)) {
            return false;
        }
        $this->effects->enqueue($effect);
        return true;
    }

    private function canEmit(int $count): bool
    {
        if ($count > $this->limits->maximumQueuedEffects - $this->effects->count()) {
            $this->close(LoginFailureCode::EFFECT_LIMIT);
            return false;
        }
        return true;
    }

    private function checkedAdd(int $base, int $delta): int
    {
        if ($base > PHP_INT_MAX - $delta) {
            throw new \OverflowException('Monotonic deadline overflow.');
        }
        return $base + $delta;
    }
}
