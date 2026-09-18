# Protocol 2193 login sessions

The `Bedriox\Server\Login` layer owns server-side, pre-spawn login policy for protocol 2193. Minecraft Bedrock 1.26.50 is the pinned wire authority and 1.26.51 is a qualified same-protocol client. Wire decoding remains in Protocol and reliable delivery remains in RakNet.

The state order is fixed: network-settings request, login, client handshake, resource-pack information response, resource-pack stack response, and login ready. Both version-bearing packets must declare protocol 2193; every other value fails closed. Empty resource-pack lists are currently advertised.

State deadlines are 5, 10, 5, 15, and 15 seconds, measured with an injected monotonic clock. Every session also has an absolute 45-second lifetime. Input packets, bytes, and pending effects are bounded. Closing clears pending inputs, outputs, derived identity state, and encryption activation effects.

`FULL` authentication is the default. `SELF_SIGNED` is an explicit insecure development mode and is never fallback after full authentication fails. `ExplicitSelfSignedLoginAuthenticator` accepts only that configured mode and a `SelfSigned` Certificate envelope containing exactly one certificate; it rejects FULL mode, Token envelopes, and every other type before proof processing. It verifies both the self-signed certificate and client-data signature against the certified identity key using the injected authentication clock. Self-signed display names, UUIDs, and keys prove only possession of a client-created key, not ownership of a Microsoft account; an empty XUID is expected and grants no account authority. Authenticators must verify identity and client-data proofs before returning `AuthenticatedLogin`; secrets and upstream exception text must not enter public failure codes.

`securityPosture()` exposes the configured mode and a stable, identity-free operator notice. The composition/startup layer must prominently log that notice whenever `requiresOperatorWarning()` is true; Login classes deliberately do not perform logging themselves.

Encryption activation follows the clear server-handshake packet. The composition adapter must install independent protocol-2193 encrypt/decrypt directions before accepting the encrypted client-handshake response. All later login packets are marked encrypted. Draining effects transfers ownership in original order.

Every inbound `LoginInput` carries protection provenance from the framing boundary. Network-settings and Login must be plaintext; the client handshake and both pack responses must have passed authenticated decryption. Encrypted early packets and plaintext late packets fail closed.

`close()` is the explicit disconnect boundary. It is idempotent and discards queued output and an unclaimed ready transfer while closing channel-owned cipher state. Cipher ownership already returned by `takeReady()` belongs to the post-login owner and is not affected by later channel destruction.

`BedrockLoginChannel` is the concrete composition boundary. It accepts only reliable-ordered, channel-zero RakNet payloads; uses Protocol for the clear `0xfe` envelope, batches, compression, packet codecs, and encryption; and emits bounded application payloads for RakNet with the same reliability and channel. The initial request and NetworkSettings response use the uncompressed batch format. Subsequent batches use negotiated zlib with threshold 256. The channel, rather than its caller, determines encryption provenance.

After encryption begins, one optional `ClientCacheStatusPacket` may precede the resource-pack response. Bedriox records the declaration but continues using non-cache level chunks; duplicate cache-status packets fail closed.

On success, `takeReady()` transfers the authenticated identity and both live directional cipher objects—with their counters and stream positions intact—to the post-login owner. Framing, decompression, packet-codec, integrity, reliability, channel, and output-limit failures close the channel and discard queued output.
