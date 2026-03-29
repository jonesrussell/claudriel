# Agent learnings — Claudriel

**What this is:** Deliberate **external memory** for coding agents and humans—short, dated notes so the next session does not rediscover the same traps. That is normal agent harness design (persist notes and code on disk; read them next run), not a vendor “self-improving model” feature. Background: [Anthropic — effective harnesses for long-running agents](https://www.anthropic.com/engineering/effective-harnesses-for-long-running-agents).

**What this is not:** A replacement for `CLAUDE.md`, `docs/specs/`, skills, or GitHub issues. Prefer specs for architecture; use this for **gotchas and workflow friction** until they are promoted elsewhere.

---

## How to use

1. **Session start:** Skim **Recent learnings** (below).
2. **Session end (or when you hit a non-obvious fix):** Add a **dated bullet**—one insight, link issue/PR/path when useful.
3. **Maintenance:** Remove or rewrite bullets when behavior changes; do not let this become an unbounded dump.

---

## Recent learnings

- **2026-03-29 — `artifact` entity type:** Registered **only** in `WorkspaceServiceProvider`. Do not re-add to `OperationsServiceProvider`; duplicate `EntityType` ids break GraphQL and storage bootstrap (#652). See `Artifact` entity docblock.
- **2026-03-29 — Admin GraphQL list filters:** Schema uses non-null `String` for filter `value`. Client composables must not pass nullable variables into those operations—use separate queries per filter shape (#651); see `usePeopleQuery.ts` / `useCommitmentsQuery.ts`.
- **2026-03-29 — `graphqlFetch` tests:** No-filter paths call `graphqlFetch(query)` with **one argument** only (`variables` is optional in `graphqlFetch.ts`). When asserting call shape, clear the mock between tests (`vi.mocked(graphqlFetch).mockClear()`) and assert `mock.calls[0].length === 1` if you mean “no variables object.”
- **2026-03-29 — Stale provider manifest:** After provider / `composer.json` discovery changes, a stale `storage/framework/packages.php` can duplicate providers—delete or regenerate; file is gitignored.

---

## Promote out of here when…

- The note describes stable architecture → `docs/specs/` (and link from here until removed).
- The note is a one-line CLAUDE.md gotcha → add to `CLAUDE.md` Critical Gotchas and delete the bullet here.
