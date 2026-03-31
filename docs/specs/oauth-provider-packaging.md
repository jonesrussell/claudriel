# OAuth provider packaging (Claudriel)

## Decision

`waaseyaa/oauth-provider` stays a **separate split repository** (`github.com/waaseyaa/oauth-provider`), published like other `waaseyaa/*` packages. It is **not** merged into the framework monorepo for now.

## Rationale

- OAuth flows and provider-specific wiring evolve on a different cadence than core entity/GraphQL code.
- The split package already ships via the same “Split Monorepo” / VCS pattern as other consumer-only extensions.
- Claudriel pins it with `dev-main` alongside other `waaseyaa/*` packages so lockfile + provenance tooling (`bin/waaseyaa-version`) treat it as part of one coherent install.

## If this changes later

If oauth-provider moves into `waaseyaa/framework`, remove the extra `repositories` / VCS entry from Claudriel’s `composer.json`, depend on the published split name only, and re-run `composer update` so `composer.lock` references the new dist/source.
