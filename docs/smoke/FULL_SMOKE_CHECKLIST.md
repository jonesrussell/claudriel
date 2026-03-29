# Full Claudriel smoke checklist

Ordered layers (fast → slow). Check boxes as you complete each step. **Owner** is whoever runs smoke (you or CI).

**References:** [.github/workflows/ci.yml](../../.github/workflows/ci.yml), [tests/smoke/v1.0-smoke-matrix.md](../../tests/smoke/v1.0-smoke-matrix.md), [tests/smoke/v1.2-public-account-smoke-matrix.md](../../tests/smoke/v1.2-public-account-smoke-matrix.md), [tests/smoke/v1.3-public-entry-funnel-smoke-matrix.md](../../tests/smoke/v1.3-public-entry-funnel-smoke-matrix.md), [docs/smoke/ide-browser-ops-2026-03-29.md](ide-browser-ops-2026-03-29.md).

---

## Layer 1 — CI parity (automated)

| Done | Step | Command |
|------|------|---------|
| [ ] | Pint | `composer lint` |
| [ ] | PHPStan | `composer analyse` |
| [ ] | PHPUnit | `composer test` |
| [ ] | Agent Black | `cd agent && python3 -m venv .venv && . .venv/bin/activate && pip install -r requirements-dev.txt && black --check .` |
| [ ] | Agent isort | `cd agent && . .venv/bin/activate && isort . --check --profile black` |
| [ ] | Agent ruff | `cd agent && . .venv/bin/activate && ruff check` |
| [ ] | Agent pytest | `cd agent && . .venv/bin/activate && python -m pytest tests/` |
| [ ] | Admin install + build | `cd frontend/admin && npm ci && npm run build` |
| [ ] | Admin Vitest | `cd frontend/admin && npm run test` |

**Notes**

- Use a project-local venv under `agent/.venv` (gitignored) if your system Python is PEP 668 managed.
- CI runs mypy with `continue-on-error`; optional locally: `cd agent && . .venv/bin/activate && mypy .`

---

## Layer 2 — Playwright admin E2E

| Done | Step | Command |
|------|------|---------|
| [ ] | Chromium e2e (matches CI) | `cd frontend/admin && CI=true npm run test:e2e` |
| [ ] | Optional: agent chat spec (local only; needs sidecar + keys) | `cd frontend/admin && npm run test:e2e:chat` |

**Playwright details**

- [playwright.config.ts](../../frontend/admin/playwright.config.ts) starts PHP on **`127.0.0.1:37840`** by default (`PLAYWRIGHT_PHP_PORT`, matches `devPorts.ts`) with **`public/router.php`**, and sets `NUXT_PUBLIC_PHP_ORIGIN` so Nuxt proxies match that PHP instance.
- In CI, `claudriel-chat-continue.spec.ts` is ignored (`testIgnore` when `CI=true`).

---

## Layer 3 — Live HTTP (scripted)

| Done | Step | Command |
|------|------|---------|
| [ ] | Ephemeral PHP + curls | `./bin/smoke-http.sh` |

**What it checks:** `GET /brief` (HTML + JSON Accept), `GET /login` non-empty body, `POST /graphql` minimal query, unknown path → `404`.

**Optional manual follow-ups (same running PHP or deployed host)**

- [ ] `GET /stream/brief` or chat stream: open briefly; no immediate `500` (full SSE not required).
- [ ] Internal API: `GET /api/internal/gmail/list` without token → `401`/`403`, not `500`.
- [ ] Internal API: valid HMAC Bearer → `200` or domain error, not `500`.

---

## Layer 4 — v1.0 core flows (matrix)

Source: [tests/smoke/v1.0-smoke-matrix.md](../../tests/smoke/v1.0-smoke-matrix.md).

**Positive**

| Done | Flow | Check |
|------|------|-------|
| [ ] | Daily brief HTML | `GET /brief` → `200`, HTML |
| [ ] | Daily brief JSON | `GET /brief` + `Accept: application/json` → `200`, JSON shape |
| [ ] | Dashboard / workspace UI | Main dashboard with workspace context when data exists |
| [ ] | Chat stream start | Stream starts; progress/response events; no `500` |
| [ ] | Chat sidecar fallback | If sidecar down, graceful degradation |
| [ ] | Workspace create via chat | Instruction creates workspace; assistant confirms |
| [ ] | Workspace delete via chat | Delete or clean missing state |
| [ ] | Brief stream | Stream connects without server error |

**Negative (v1.0 table)**

| Done | Case | Expected |
|------|------|----------|
| [ ] | Bad route near brief/dashboard | `404`, not `500` |
| [ ] | Chat without Anthropic config | `503` or clear config error, not opaque `500` |
| [ ] | Sidecar unhealthy | Non-fatal path |
| [ ] | Delete non-existent workspace | Clean response, no crash |
| [ ] | Duplicate workspace create | Idempotent / reported existing |
| [ ] | Post-deploy brief + chat | Still healthy |

**Proactive / temporal rows in v1.0** — exercise when those features are in scope for your release.

---

## Layer 5 — v1.2 public account (matrix)

Source: [tests/smoke/v1.2-public-account-smoke-matrix.md](../../tests/smoke/v1.2-public-account-smoke-matrix.md).

| Done | Flow |
|------|------|
| [ ] | Public signup |
| [ ] | Email verification |
| [ ] | Login |
| [ ] | Password reset |
| [ ] | New-tenant dashboard / brief fallback |
| [ ] | New-tenant chat local action |
| [ ] | Workspace CRUD `/api/workspaces` (tenant-scoped) |

---

## Layer 6 — v1.3 public entry funnel (matrix)

Source: [tests/smoke/v1.3-public-entry-funnel-smoke-matrix.md](../../tests/smoke/v1.3-public-entry-funnel-smoke-matrix.md).

| Done | Flow |
|------|------|
| [ ] | Marketing homepage `GET /` |
| [ ] | Anonymous `GET /app` → login |
| [ ] | Login → `/app` with context |
| [ ] | Authenticated revisit `GET /` → `/app` |
| [ ] | Verify + onboarding bootstrap → `/app` |

**Staging shortcuts (from matrix)**

- [ ] `curl -si https://claudriel.northcloud.one/` — homepage markers
- [ ] `curl -si https://claudriel.northcloud.one/app` — `302` → `/login`
- [ ] `curl -si https://claudriel.northcloud.one/login` — auth surface

---

## Layer 7 — CLI spot-check

| Done | Step | Command |
|------|------|---------|
| [ ] | List commands | `php bin/waaseyaa list` (requires full `.env` / boot; see note below) |
| [ ] | Day brief | `php bin/waaseyaa claudriel:brief` |
| [ ] | Commitments | `php bin/waaseyaa claudriel:commitments` |

**Note:** Console kernel needs the same env and provider wiring as a full app boot. If `list` fails with missing bindings, fix env from `.env.example` and ensure providers register before re-running smoke.

---

## Layer 8 — Python agent subprocess (optional)

| Done | Step |
|------|------|
| [ ] | Docker image / `AGENT_DOCKER_IMAGE` available |
| [ ] | One read-only internal API call with valid HMAC token |
| [ ] | Mark **N/A** if keys or image unavailable |

---

## Layer 9 — IDE browser / ops admin (manual)

Follow [ide-browser-ops-2026-03-29.md](ide-browser-ops-2026-03-29.md).

**Environment**

- [ ] PHP: `php -S 127.0.0.1:<port> -t public public/router.php` (use `router.php`; optional `CLAUDRIEL_DEV_CLI_SESSION=1` for cli-server session).
- [ ] Nuxt: `npm run dev` from `frontend/admin` (default **37841**; PHP **37840** per `devPorts.ts`; override with `NUXT_PUBLIC_PHP_ORIGIN` / `NUXT_DEV_SERVER_PORT` if needed).

**Minimum**

| Done | Check |
|------|-------|
| [ ] | `/admin/today` shell, brief actions |
| [ ] | `/admin/workspaces` lists via GraphQL |
| [ ] | `/admin/pipeline` loads |
| [ ] | Chat rail opens (Send optional if no agent) |
| [ ] | Logout visible |

**Deep (optional)**

| Done | Check |
|------|-------|
| [ ] | Live brief stream end-to-end |
| [ ] | Agent Send with real backend |
| [ ] | Entity grids create/edit |
| [ ] | Logout round-trip |
| [ ] | `/api/broadcast` / realtime: avoid worker spin (#564) |

---

## Layer 10 — Staging / production (release)

| Done | Check |
|------|-------|
| [ ] | Repeat Layer 3–4 subset against staging URL |
| [ ] | Repeat against production |
| [ ] | Production admin is static under PHP host; `/api` and `/graphql` same-origin (no Nuxt dev proxy) |
| [ ] | TLS: treat `--insecure` deploy scripts as a smell; fix certs when possible (#474) |

---

## Record results

Date: _______________  
Branch / commit: _______________  
Failures (link issues): _______________
