# Change safety

Established working behavior is frozen unless a task explicitly requires changing it. This rule applies even when a neighboring implementation looks inconsistent or a test suite would still pass.

## Before editing

Record four facts in the working note or pull request:

1. the problem and last proven failure boundary;
2. the owning repository and intended file areas;
3. the observable outcome that may change;
4. the earlier behavior that must remain unchanged.

Run or add a characterization test before changing a working compatibility surface. Protected surfaces include discovery fields, protocol and client admission, packet IDs and layouts, encryption transitions, registries and hashes, chunk framing, authentication defaults, ports, game mode, settings defaults, and component pins.

If investigation shows a different owner is responsible, revise the scope before editing. Do not use speculative fixes across transport, protocol, registry, and world layers simultaneously. Instrument the narrowest safe boundary, reproduce once, and remove temporary instrumentation before the final gate.

## During implementation

Make the smallest coherent change in the owning layer. Do not patch `vendor/`, duplicate a component implementation in Bedriox, or depend on an undocumented sibling internal. Avoid opportunistic renaming, formatting, dependency updates, defaults, and cleanup.

Every external input remains bounded. Add success, rejection, malformed-input, resource-limit, cleanup, and regression coverage appropriate to the boundary. A production encoder and decoder must not be the only witnesses for the same wire vector.

After each meaningful slice, run focused tests. If a formerly passing behavior changes unexpectedly, stop the milestone and diagnose the regression before continuing.

When a reference server is used, trace the whole feature conversation before coding: all packet entry forms, handler ordering, prediction reconciliation, authoritative state mutation, success response, rejection correction, peer projection, and the next action that consumes the new state. Write down which upstream files were reviewed and test the complete local path. Matching one method or packet in isolation is not sufficient evidence.

For gameplay, PocketMine-MP is the required primary implementation reference. Audit the complete feature path and its dependent translators, registries, enum maps, and state trackers before designing the Bedriox change. Record a field-by-field comparison rather than relying on remembered constants or locally invented fixtures. Inventory reviews must cover the advertised StartGame mode, dedicated and embedded request entry points, container translation, stack/request IDs, staged mutation, response building, correction, and subsequent item use. Cloudburst or official schemas remain valuable independent wire checks, but they do not replace the PMMP behavior-path review.

## Before commit or handoff

Run:

```shell
composer check
php bin/bedriox --version
```

For cross-repository or compatibility changes, also run:

```powershell
powershell.exe -NoProfile -File tools/verify-workspace.ps1
```

Then inspect:

```shell
git status --short
git diff --name-only
git diff --stat
git diff --check
git diff
```

Every file and behavior in the diff must be explained by the declared scope. Confirm documentation, changelog, compatibility, notices, and exact pins are current. Never use `-SkipClean` as a release or completion gate.

## Milestone gate

A milestone completes only when focused tests, the repository gate, the applicable workspace gate, and pushed CI are green. Gameplay-visible work also requires the relevant [client journey](client-journey-contract.md) to pass. Record limitations honestly. Do not start the next milestone while the current one is failing or awaiting required qualification.

When a defect escapes, add its regression first, document the root cause and missed gate, and improve the gate that should have caught it. Do not merely patch the observed value.
