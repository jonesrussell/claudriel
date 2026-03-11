# Sidecar Production Deployment Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deploy the existing sidecar Docker container to production alongside the PHP site, and add basic auth to the production Caddyfile.

**Architecture:** The sidecar runs as a single Docker container on `northcloud.one`, bound to `127.0.0.1:8100`. PHP-FPM reaches it via localhost. The deploy workflow builds it on the server after each PHP deploy. Caddy gets basic auth with env var substitution via systemd `EnvironmentFile`.

**Tech Stack:** Docker Compose, Caddy, PHP Deployer, GitHub Actions, systemd

**Spec:** `docs/superpowers/specs/2026-03-11-sidecar-production-deploy-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `docker-compose.sidecar.yml` | Create | Production-only sidecar compose (builds from `./docker-context`) |
| `Caddyfile` | Modify | Add basic auth with `/api/ingest` exemption |
| `deploy.php` | Modify | Add `deploy:sidecar_dir` task to create persistent `sidecar/` directory |
| `.github/workflows/deploy.yml` | Modify | Add sidecar deploy step (SSH copy + docker compose up) |

No new test files. This is infrastructure-only (Docker, Caddy, CI config). The sidecar Python code and PHP integration already exist and work.

---

## Chunk 1: Production Compose File and Caddyfile

### Task 1: Create docker-compose.sidecar.yml

**Files:**
- Create: `docker-compose.sidecar.yml`

- [ ] **Step 1: Create the production compose file**

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

Note: `build: ./docker-context` is relative to where the compose file lives on the server (`/home/deployer/claudriel/sidecar/`). The deploy step copies `docker/sidecar/` into `sidecar/docker-context/`.

- [ ] **Step 2: Verify YAML is valid**

Run: `python3 -c "import yaml; yaml.safe_load(open('docker-compose.sidecar.yml'))"`
Expected: No output (valid YAML)

- [ ] **Step 3: Commit**

```bash
git add docker-compose.sidecar.yml
git commit -m "feat: add production sidecar compose file"
```

---

### Task 2: Add basic auth to Caddyfile

**Files:**
- Modify: `Caddyfile`

The Caddyfile currently starts with the site block at line 4. Basic auth must be added inside the block, before any `handle` directives. The `/api/ingest` endpoint is exempted since it has its own bearer token auth and the sidecar needs unauthenticated access.

- [ ] **Step 1: Add basic auth block to Caddyfile**

Insert after the `tls` block (after line 8) and before the `root` directive (line 10):

```
  @not_ingest {
    not path /api/ingest
  }
  basicauth @not_ingest {
    {$BASIC_AUTH_USER} {$BASIC_AUTH_HASH}
  }
```

The `{$BASIC_AUTH_USER}` and `{$BASIC_AUTH_HASH}` syntax references environment variables. These are loaded by Caddy from its process environment (configured via systemd `EnvironmentFile` in the server setup step).

- [ ] **Step 2: Verify Caddyfile syntax**

Run: `caddy fmt --overwrite Caddyfile && caddy validate --config Caddyfile 2>&1 || echo "Caddy not installed locally, will validate on server"`

If `caddy` is not installed locally, visually verify the block is correctly placed inside the site block and properly indented.

- [ ] **Step 3: Commit**

```bash
git add Caddyfile
git commit -m "feat: add basic auth to production Caddyfile

Protects all routes except /api/ingest (has its own bearer auth).
Credentials loaded from env vars via systemd EnvironmentFile."
```

---

## Chunk 2: Deploy Pipeline Integration

### Task 3: Add sidecar directory task to deploy.php

**Files:**
- Modify: `deploy.php`

- [ ] **Step 1: Add the sidecar directory task**

Add after the existing `deploy:copy_caddyfile` task (after line 59):

```php
desc('Ensure sidecar directory exists');
task('deploy:sidecar_dir', function (): void {
    run('mkdir -p {{deploy_path}}/sidecar');
});
```

- [ ] **Step 2: Add the task to the deploy flow**

In the `task('deploy')` array (line 76), add `'deploy:sidecar_dir'` after `'deploy:setup'`:

```php
task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:sidecar_dir',
    'deploy:lock',
    'deploy:release',
    'deploy:upload',
    'deploy:shared',
    'deploy:copy_caddyfile',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
    'caddy:reload',
    'php-fpm:reload',
]);
```

- [ ] **Step 3: Commit**

```bash
git add deploy.php
git commit -m "feat: add deploy:sidecar_dir task to Deployer config"
```

---

### Task 4: Add sidecar deploy step to GitHub Actions workflow

**Files:**
- Modify: `.github/workflows/deploy.yml`

- [ ] **Step 1: Add sidecar deploy step after the existing Deploy step**

Add after the `Deploy` step (after line 107, the last line of the file):

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

This step:
1. Creates the persistent `sidecar/` directory if it doesn't exist
2. Copies the compose file from the current release
3. Replaces the Docker build context with the latest from the release
4. Builds and restarts the sidecar (Docker caches layers, so no-op if unchanged)

- [ ] **Step 2: Verify the workflow YAML is valid**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"`
Expected: No output (valid YAML)

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "feat: add sidecar container deploy step to CI workflow"
```

---

## Chunk 3: Server Setup (Manual)

### Task 5: One-time server configuration

These steps are performed manually via SSH. They only need to run once before the first sidecar deploy.

**SSH access:** `jones@northcloud.one` (sudo) and `deployer@claudriel.northcloud.one`

- [ ] **Step 1: Ensure deployer is in the docker group**

```bash
ssh jones@northcloud.one 'sudo usermod -aG docker deployer'
```

Verify: `ssh deployer@claudriel.northcloud.one 'docker ps'`
Expected: No permission error (may show empty container list)

- [ ] **Step 2: Generate basic auth password hash**

```bash
ssh jones@northcloud.one 'caddy hash-password'
```

Enter a password when prompted. Copy the bcrypt hash output (starts with `$2a$`).

- [ ] **Step 3: Add env vars to shared/.env**

```bash
ssh deployer@claudriel.northcloud.one 'cat >> /home/deployer/claudriel/shared/.env << EOF

# Basic auth (Caddy)
BASIC_AUTH_USER=jones
BASIC_AUTH_HASH=<paste the bcrypt hash from step 2>

# Sidecar
SIDECAR_URL=http://127.0.0.1:8100
CLAUDRIEL_SIDECAR_KEY=<generate with: openssl rand -hex 32>
EOF'
```

Generate the sidecar key first: `openssl rand -hex 32`

- [ ] **Step 4: Add systemd EnvironmentFile override for Caddy**

```bash
ssh jones@northcloud.one 'sudo mkdir -p /etc/systemd/system/caddy.service.d && sudo tee /etc/systemd/system/caddy.service.d/env.conf << EOF
[Service]
EnvironmentFile=/home/deployer/claudriel/shared/.env
EOF
sudo systemctl daemon-reload
sudo systemctl restart caddy'
```

Verify: `ssh jones@northcloud.one 'sudo systemctl status caddy'`
Expected: Active (running), no errors

- [ ] **Step 5: Copy Claude OAuth tokens to server**

From your local machine:

```bash
scp -r ~/.claude deployer@claudriel.northcloud.one:/home/deployer/.claude
scp ~/.claude.json deployer@claudriel.northcloud.one:/home/deployer/.claude.json
```

Verify: `ssh deployer@claudriel.northcloud.one 'ls -la ~/.claude/ ~/.claude.json'`
Expected: Files exist with content

- [ ] **Step 6: Push code changes and verify deploy**

```bash
git push
```

Wait for GitHub Actions to complete. Then verify:

```bash
# Check sidecar container is running
ssh deployer@claudriel.northcloud.one 'docker ps'
# Expected: sidecar container running, healthy

# Check sidecar health endpoint
ssh deployer@claudriel.northcloud.one 'curl -s http://127.0.0.1:8100/health'
# Expected: {"status": "ok", "active_sessions": 0}

# Check basic auth is active (should get 401 without credentials)
curl -s -o /dev/null -w "%{http_code}" https://claudriel.northcloud.one
# Expected: 401

# Check basic auth works with credentials
curl -s -o /dev/null -w "%{http_code}" -u jones:<password> https://claudriel.northcloud.one
# Expected: 200

# Check /api/ingest is exempt from basic auth
curl -s -o /dev/null -w "%{http_code}" https://claudriel.northcloud.one/api/ingest
# Expected: 401 or 403 (API key auth, not basic auth)
```

---

## Verification Checklist

After all tasks are complete, verify:

- [ ] `docker-compose.sidecar.yml` exists in repo root
- [ ] Caddyfile has `basicauth` block with `{$BASIC_AUTH_USER}` / `{$BASIC_AUTH_HASH}`
- [ ] Caddyfile exempts `/api/ingest` from basic auth
- [ ] `deploy.php` has `deploy:sidecar_dir` task in the deploy flow
- [ ] `.github/workflows/deploy.yml` has sidecar deploy step after PHP deploy
- [ ] Sidecar container is running on production (`docker ps`)
- [ ] Sidecar health check passes (`curl localhost:8100/health`)
- [ ] Basic auth blocks unauthenticated access to dashboard
- [ ] Basic auth allows authenticated access
- [ ] `/api/ingest` is accessible without basic auth
- [ ] Chat works via the sidecar (send a message, get response with tool access)
