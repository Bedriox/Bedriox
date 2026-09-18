# Contributing

Bedriox is currently in private incubation. Authorized contributors should open focused pull requests with tests, documentation, and appropriate attribution for externally informed protocol behavior.

Before changing code, identify the owning repository, intended file areas, observable outcome, and established behavior that must remain unchanged. Add a characterization test first when touching a working compatibility surface. Do not bundle opportunistic cleanup, protocol changes, defaults, dependency updates, or cross-repository edits into an unrelated feature.

Every pull request must state:

- the problem and deliberately bounded scope;
- the protected behavior rerun after the change;
- focused, repository, workspace, and retail-client validation performed;
- every component pin, public contract, security boundary, and third-party notice affected;
- any known limitation or deferred work.

Reported crashes and retail disconnects require regression coverage at the last observable failure boundary. Tests accumulate across milestones: new tests supplement rather than replace the established discovery, connection, login, spawn, terrain, movement, chat, interaction-safety, disconnect, and reconnect baseline.

Make changes in the repository that owns the behavior. Never patch `vendor/`. For a coordinated component change, land and verify the owner first, then update Bedriox's exact pin and consumer integration in a separate focused commit. See [change safety](docs/change-safety.md) and [cross-repository changes](docs/cross-repository-changes.md).

Before committing, run the applicable checks and inspect the complete diff. A change is not ready while any file, behavior change, generated artifact, or dependency update is unexplained. Do not proceed to a later milestone until the current milestone passes its complete acceptance gate.

Use focused commit messages without personal email addresses or identity trailers. By contributing, you confirm that you have the right to submit the work under the repository license. Do not copy code or data whose license is unknown or incompatible.
