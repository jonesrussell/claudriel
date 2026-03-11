# Sidecar Production Deployment Design

**Goal:** Deploy the existing Claudriel sidecar (Python FastAPI + Claude Agent SDK) as a Docker container on `claudriel.northcloud.one`, alongside the natively deployed PHP site, and add basic auth to the production site.

## Context

Claudriel's chat interface can use a sidecar service that wraps the Claude Agent SDK to provide Gmail and Calendar access via MCP tools. The sidecar code already exists (`docker/sidecar/`), works locally via `docker-compose.yml`, and the PHP integration (`SidecarChatClient`, `ChatStreamController`) is complete. Without the sidecar, chat falls back to direct Anthropic API with no tool access.

The production PHP site is deployed via PHP Deployer (artifact upload pattern) with Caddy and PHP-FPM running natively (no Docker). The sidecar is the only Docker workload.

## Architecture

```
Browser (SSE) -- Caddy (basic auth) -- PHP-FPM (ChatStreamController)
                                            |
                                            | HTTP POST localhost:8100
                                            v
                                   Docker: sidecar container (FastAPI)
                                            |
                                            v
                                   Claude Agent SDK -> Claude CLI
                                            |
                                            v
                                   Anthropic MCP (Gmail, Calendar)
```

- Sidecar container binds to `127.0.0.1:8100` only (not internet-exposed)
- PHP discovers sidecar via `SIDECAR_URL` env var and health check
- Falls back to direct Anthropic API if sidecar is unavailable
- Claude OAuth tokens mounted read-only from `/home/deployer/.claude/`

## Production Compose File

A new `docker-compose.sidecar.yml` at the repo root. Only the sidecar service; no PHP or Caddy (those run natively).

```yaml
services:
  sidecar:
    build: ./docker-context
    ports:
      - "127.0.0.1:8100:8100"
    environment:
      - CLAUDRIEL_SIDECAR_KEY=${CLAUDRIEL_SIDECAR_KEY}
      - CLAUDRIEL_API_KEY=${CLAUDRIEL_API_KEY}
      - CLAUDRIEL_INGEST_URL=https://claudriel.northcloud.one/api/ingest
      - SESSION_TIMEOUT_MINUTES=15
      - CLAUDE_MODEL=${CLAUDE_MODEL:-claude-sonnet-4-6}
    volumes:
      - /home/deployer/.claude:/root/.claude-config:ro
      - /home/deployer/.claude.json:/root/.claude.json:ro
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8100/health"]
      interval: 10s
      timeout: 5s
      retries: 3
```

## Filesystem Layout

```
/home/deployer/claudriel/
  current -> releases/20260311...    (PHP app, rotates per deploy)
  shared/                            (.env, waaseyaa.sqlite, persists)
  sidecar/                           (persists across deploys)
    docker-compose.sidecar.yml
    docker-context/                  (copy of docker/sidecar/ from release)
  releases/
```

The `sidecar/` directory lives outside the rotating releases structure. On each deploy, the compose file and Docker build context are copied from the current release into `sidecar/`. Docker only rebuilds if the context has changed.

## Deploy Workflow Integration

Two new steps added to `.github/workflows/deploy.yml` after the existing `dep deploy production` step. These run via SSH as the `deployer` user:

```yaml
      - name: Deploy sidecar container
        run: |
          ssh deployer@claudriel.northcloud.one '
            cd /home/deployer/claudriel
            mkdir -p sidecar
            cp current/docker-compose.sidecar.yml sidecar/
            rm -rf sidecar/docker-context
            cp -r current/docker/sidecar sidecar/docker-context
            cd sidecar
            docker compose -f docker-compose.sidecar.yml --env-file ../shared/.env up -d --build
          '
```

A corresponding Deployer task in `deploy.php` ensures the `sidecar/` directory exists:

```php
desc('Ensure sidecar directory exists');
task('deploy:sidecar_dir', function (): void {
    run('mkdir -p {{deploy_path}}/sidecar');
});
```

Added to the deploy flow after `deploy:setup`.

The sidecar restarts only when its source files change. PHP deploys that don't touch `docker/sidecar/` result in a no-op Docker rebuild (cached layers).

## Basic Auth

Caddy basic auth protects all routes. The `/api/ingest` endpoint is exempted because it has its own bearer token auth (`CLAUDRIEL_API_KEY`) and the sidecar needs unauthenticated HTTP access to it.

Credentials are stored in `shared/.env` and loaded via Caddy's environment variable substitution. Caddy reads env vars from its process environment, so a systemd override is needed:

```bash
sudo systemctl edit caddy
# Add:
# [Service]
# EnvironmentFile=/home/deployer/claudriel/shared/.env
```

Caddyfile uses env var references (no secrets committed to git):

```
claudriel.northcloud.one {
  @not_ingest {
    not path /api/ingest
  }
  basicauth @not_ingest {
    {$BASIC_AUTH_USER} {$BASIC_AUTH_HASH}
  }
  # ... rest of existing config
}
```

Values in `shared/.env`:
```
BASIC_AUTH_USER=jones
BASIC_AUTH_HASH=$2a$14$...   # generated via caddy hash-password
```

## File Changes

| File | Action | Responsibility |
|------|--------|---------------|
| `docker-compose.sidecar.yml` | Create | Production sidecar service definition |
| `Caddyfile` | Modify | Add basic auth with `/api/ingest` exemption |
| `.github/workflows/deploy.yml` | Modify | Add sidecar copy + build/restart steps |
| `deploy.php` | Modify | Create `sidecar/` directory in deploy setup |

No changes to:
- `docker/sidecar/` (Python code, Dockerfile, entrypoint) - already works
- `src/Domain/Chat/SidecarChatClient.php` - already works
- `src/Controller/ChatStreamController.php` - already detects sidecar via health check

## One-Time Manual Setup

Before first deploy with sidecar:

1. **Claude tokens:** Copy `~/.claude/` directory and `~/.claude.json` file to `/home/deployer/` on the server. These contain OAuth tokens for Gmail/Calendar MCP access.
2. **Basic auth password:** Run `caddy hash-password` on the server, add `BASIC_AUTH_USER` and `BASIC_AUTH_HASH` to `shared/.env`. Add a systemd override for Caddy: `sudo systemctl edit caddy` with `[Service]\nEnvironmentFile=/home/deployer/claudriel/shared/.env`, then `sudo systemctl restart caddy`.
3. **Sidecar env vars:** Add to `/home/deployer/claudriel/shared/.env`:
   - `SIDECAR_URL=http://127.0.0.1:8100`
   - `CLAUDRIEL_SIDECAR_KEY=<generate a random key>` (shared secret between PHP and sidecar)
   - `CLAUDRIEL_API_KEY` and `ANTHROPIC_API_KEY` should already exist from prior setup
4. **Docker permissions:** Ensure `deployer` user is in the `docker` group

## Token Refresh

Claude OAuth tokens may expire. When they do, the sidecar health check will still pass (FastAPI runs fine), but chat requests that use Gmail/Calendar tools will fail. The sidecar's error handling will surface this as a `chat-error` SSE event. To refresh: re-authenticate Claude CLI on a machine with browser access, then copy the updated tokens to the server.

## Error Handling

- **Sidecar container down:** PHP health check fails, falls back to direct Anthropic API (no tools, honest about it per the system prompt fix already deployed)
- **OAuth tokens expired:** Sidecar returns tool errors, surfaced to user via chat-error event
- **Docker not running:** Same as container down, graceful fallback
- **Ingest endpoint unreachable:** Sidecar logs error but chat still works (ingestion is best-effort)
