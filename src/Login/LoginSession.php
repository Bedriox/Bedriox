<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\ClientCacheStatusPacket;
use Bedriox\Protocol\Packet\ClientToServerHandshakePacket;
use Bedriox\Protocol\Packet\CompressionAlgorithm;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Packet\NetworkSettingsPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PlayStatus;
use Bedriox\Protocol\Packet\PlayStatusPacket;
use Bedriox\Protocol\Packet\RequestNetworkSettingsPacket;
use Bedriox\Protocol\Packet\ResourcePackClientResponsePacket;
use Bedriox\Protocol\Packet\ResourcePackResponseStatus;
use Bedriox\Protocol\Packet\ResourcePacksInfoPacket;
use Bedriox\Protocol\Packet\ResourcePackStackPacket;
use Bedriox\Protocol\Packet\ServerToClientHandshakePacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\HandshakeJwt;
use Bedriox\Protocol\Security\P384;
use SplQueue;
use Throwable;

/** Server-owned, transport-independent login state machine for the active compatible protocol family. */
final class LoginSession
{
    private const ABSOLUTE_TIMEOUT_NS = 45_000_000_000;

    /** @var SplQueue<LoginInput> */
    private SplQueue $input;

    /** @var SplQueue<LoginEffect> */
    private SplQueue $effects;

    private LoginState $state = LoginState::WAIT_NETWORK_REQUEST;
    private ?LoginFailureCode $failure = null;
    private int $queuedInputBytes = 0;
    private int $lastNow;
    private int $stateDeadline;
    private readonly int $absoluteDeadline;
    private ?AuthenticatedLogin $authenticated = null;
    private bool $clientCacheStatusReceived = false;
    private ?int $observedProtocolVersion = null;
    private ?string $failureDetail = null;

    public function __construct(
        private readonly MonotonicClock $clock,
        private readonly LoginAuthenticator $authenticator,
        private readonly HandshakeMaterialFactory $handshakes,
        private readonly AuthenticationMode $authenticationMode = AuthenticationMode::FULL,
        private readonly LoginLimits $limits = new LoginLimits(),
    ) {
        $this->input = new SplQueue();
        $this->effects = new SplQueue();
        $this->lastNow = $clock->nowNanoseconds();
        $this->absoluteDeadline = $this->checkedAdd($this->lastNow, self::ABSOLUTE_TIMEOUT_NS);
        $this->stateDeadline = $this->checkedAdd($this->lastNow, 5_000_000_000);
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
        if ($input->wireBytes > $this->limits->maximumPacketBytes
            || $this->input->count() >= $this->limits->maximumQueuedPackets
            || $input->wireBytes > $this->limits->maximumQueuedInputBytes - $this->queuedInputBytes) {
            $this->close(LoginFailureCode::INPUT_LIMIT);
            return false;
        }
        $this->input->enqueue($input);
        $this->queuedInputBytes += $input->wireBytes;
        return true;
    }

    /** Process at most the packets present at entry, preventing callback-driven unbounded work. */
    public function tick(): void
    {
        if (!$this->preflight()) {
            return;
        }
        $remaining = $this->input->count();
        while ($remaining-- > 0 && $this->state !== LoginState::CLOSED && $this->state !== LoginState::LOGIN_READY) {
            if (!$this->checkTime()) {
                break;
            }
            $input = $this->input->dequeue();
            $this->queuedInputBytes -= $input->wireBytes;
            $expectsEncryption = match ($this->state) {
                LoginState::WAIT_NETWORK_REQUEST, LoginState::WAIT_LOGIN => false,
                default => true,
            };
            if ($input->encrypted !== $expectsEncryption) {
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
        $this->queuedInputBytes = 0;
    }

    private function handle(Packet $packet): void
    {
        match ($this->state) {
            LoginState::WAIT_NETWORK_REQUEST => $this->handleNetworkRequest($packet),
            LoginState::WAIT_LOGIN => $this->handleLogin($packet),
            LoginState::WAIT_CLIENT_HANDSHAKE => $this->handleClientHandshake($packet),
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
        if (!$this->emit(new SendPacketEffect(new NetworkSettingsPacket(256, CompressionAlgorithm::Zlib, false, 0, 0.0), false))) {
            return;
        }
        $this->transition(LoginState::WAIT_LOGIN, 10);
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
        $expected = $this->authenticationMode === AuthenticationMode::FULL ? AuthenticationType::Full : AuthenticationType::SelfSigned;
        if ($packet->authentication->type !== $expected) {
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
        try {
            $material = $this->handshakes->create();
        } catch (Throwable $exception) {
            $this->failureDetail = 'handshake_material:' . $exception::class;
            $this->close(LoginFailureCode::CRYPTOGRAPHIC_FAILURE);
            return;
        }
        try {
            $shared = P384::deriveSharedSecret($material->keyPair->privateKey, $login->identityPublicKey);
        } catch (Throwable $exception) {
            $this->failureDetail = 'shared_secret:' . $exception::class;
            $this->close(LoginFailureCode::CRYPTOGRAPHIC_FAILURE);
            return;
        }
        try {
            $sessionKey = P384::deriveSessionKey($material->salt, $shared);
        } catch (Throwable $exception) {
            $this->failureDetail = 'session_key:' . $exception::class;
            $this->close(LoginFailureCode::CRYPTOGRAPHIC_FAILURE);
            return;
        }
        try {
            $jwt = HandshakeJwt::create($material->keyPair, $material->salt);
        } catch (Throwable $exception) {
            $this->failureDetail = 'handshake_jwt:' . $exception::class;
            $this->close(LoginFailureCode::CRYPTOGRAPHIC_FAILURE);
            return;
        }
        if (!$this->preflight()) {
            return;
        }
        $this->authenticated = $login;
        if (!$this->emit(new SendPacketEffect(new ServerToClientHandshakePacket($jwt), false))) {
            return;
        }
        if (!$this->emit(new EnableEncryptionEffect($sessionKey))) {
            return;
        }
        $this->transition(LoginState::WAIT_CLIENT_HANDSHAKE, 5);
    }

    private function handleClientHandshake(Packet $packet): void
    {
        if (!$packet instanceof ClientToServerHandshakePacket) {
            $this->close(LoginFailureCode::UNEXPECTED_PACKET);
            return;
        }
        if (!$this->canEmit(2)) {
            return;
        }
        $this->effects->enqueue(new SendPacketEffect(new PlayStatusPacket(PlayStatus::LoginSuccess), true));
        $this->effects->enqueue(new SendPacketEffect(new ResourcePacksInfoPacket(false, false, false, false, '00000000-0000-0000-0000-000000000000', '', []), true));
        $this->transition(LoginState::WAIT_PACK_INFO_RESPONSE, 15);
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
            $this->close(LoginFailureCode::UNEXPECTED_PACKET);
            return;
        }
        if ($packet->status !== ResourcePackResponseStatus::HaveAllPacks || $packet->packIds !== []) {
            $this->close(LoginFailureCode::INVALID_PACK_RESPONSE);
            return;
        }
        $protocolVersion = $this->observedProtocolVersion;
        if ($protocolVersion === null) {
            $this->close(LoginFailureCode::UNSUPPORTED_PROTOCOL);
            return;
        }
        if (!$this->emit(new SendPacketEffect(new ResourcePackStackPacket(false, [], ProtocolVersion::gameVersion($protocolVersion), [], false, false), true))) {
            return;
        }
        $this->transition(LoginState::WAIT_PACK_STACK_RESPONSE, 15);
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
        $this->state = $state;
        $this->stateDeadline = $this->checkedAdd($this->lastNow, $seconds * 1_000_000_000);
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
