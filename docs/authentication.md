# Protocol 2193 login authentication

## FULL Token authentication

`Bedriox\Server\Authentication\FullTokenAuthenticator` implements the bounded, offline verification boundary for protocol 2193 `FULL` Token login authentication. It accepts a decoded login authentication value and the separate client-data JWT. It does not perform HTTP requests.

The application injects an `AuthenticationClock` and a `JwkProvider` whose snapshot was obtained by an operator-controlled discovery/cache component. The verifier selects only by the token's bounded `kid`, rejects duplicate identifiers, accepts only public RSA signing JWKs of at least 2048 bits, and assigns trusted `RS256` when the optional JWK `alg` is absent. Token-supplied key locations and keys are never resolved.

The authorization token must use `RS256`, the exact Minecraft Services issuer and multiplayer audience, valid integral time claims, and the required subject, `cpk`, `xname`, and decimal `xid` claims. `mid` is optional. The `cpk` must be canonical standard-base64 P-384 DER SPKI. Only after authorization succeeds is client-data ES384 verified with that exact key; appearance claims are not exposed before proof succeeds.

All compact input, JSON, keys, identifiers, claims, and clock skew have explicit limits. Failures are closed and do not include token or key contents.

## Production discovery and key cache

`MinecraftDiscoveryJwkProvider` starts only from the fixed MinecraftPE discovery URL. It requires the exact production authentication service and OpenID issuer, and restricts every request to HTTPS on the two expected Minecraft Services hosts without credentials, IP literals, custom ports, fragments, or redirects. `CurlHttpsJsonTransport` verifies the TLS peer and host with TLS 1.2 or newer, uses two-second connect and five-second total deadlines, and stops response bodies at the caller's byte bound.

Discovery and OpenID documents are capped at 64 KiB, JWKS at 256 KiB, JSON at depth eight and a bounded lexical token count, and each atomic snapshot at 64 unique canonical RSA signing keys. A successful snapshot refreshes after one hour and becomes unusable after 24 hours by default. A failed explicit refresh leaves an unexpired last-known-good snapshot intact; cold and hard-stale reads fail immediately.

FULL authentication treats the validated issuer and `sub` claim as the account identity. Because the retail subject is opaque rather than a Bedrock wire UUID, Bedriox deterministically maps it to RFC 4122 UUIDv5 under the DNS namespace using the domain-separated name `bedriox:full:<subject>`. The mapping is stable across sessions and does not trust device identity or optional client-supplied UUID fields.

Network refresh is an explicit startup/scheduler operation through `refresh()` and must run outside the simulation tick. `keys()` performs no I/O. `needsRefresh()` reports cold, TTL, or requested refresh state only when an attempt is eligible. Failed attempts apply exponential backoff from five seconds up to five minutes by default; neither scheduler polling nor forced freshness bypasses an active failure cooldown. `refresh(true)` may bypass a healthy snapshot's TTL for an operator-requested rotation check. An unknown bounded `KeyId` can only set one refresh request per configured interval; it does not fetch synchronously during token verification. PHP's synchronous execution supplies the single-flight guard; callers must not share one mutable provider across worker processes without an external cache coordinator.

The transport and clock are injected for deterministic testing. Live discovery is intentionally opt-in and is not part of the normal test gate. Retail-client interoperability qualification remains separate work.

## Explicit self-signed development mode

`ExplicitSelfSignedLoginAuthenticator` is a separate, deliberately insecure adapter enabled only when the server selects `AuthenticationMode::SELF_SIGNED`. It requires a protocol `SelfSigned` Certificate envelope with exactly one certificate, verifies that certificate in the Protocol library's explicit self-signed mode, and verifies the separate client-data JWS with the identity public key certified by that proof. Certificate validity uses the injected `AuthenticationClock`.

This mode proves possession of a client-generated P-384 key only. Its display name and UUID are self-asserted, its XUID may be empty, and none of those values establish a Microsoft/Xbox identity or entitlement. Do not use them for account-level permissions, purchases, bans shared with FULL mode, or any decision requiring online identity. The adapter rejects FULL mode, Full authentication types, Token envelopes, multi-certificate chains, expired or invalid signatures, and invalid client-data proofs. It never retries a failed FULL login or downgrades one to self-signed verification.
