# Dev Experience Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the two-repo copy workflow with symlinked code and a Docker-based Caddy+PHP-FPM dev server.

**Architecture:** ~/claudriel becomes symlinks to ~/dev/claudriel for all code, keeping only data files (sqlite, storage/, .env) local. Docker Compose runs Caddy reverse-proxying to PHP-FPM for proper concurrency (SSE works, no polling workaround needed).

**Tech Stack:** Docker, Caddy 2 (Alpine), PHP 8.4-FPM (Alpine + SQLite extension), Bash

**Spec:** `docs/plans/2026-03-10-dev-experience-design.md`

---

## Chunk 1: Docker Dev Server Files

### Task 1: Create Dockerfile for PHP-FPM with SQLite

The base `php:8.4-fpm-alpine` image lacks the SQLite PDO extension. We need a small Dockerfile.

**Files:**
- Create: `docker/php/Dockerfile`

- [ ] **Step 1: Create docker directory**

```bash
mkdir -p docker/php
```

- [ ] **Step 2: Write the Dockerfile**

```dockerfile
FROM php:8.4-fpm-alpine

RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo_sqlite

WORKDIR /srv
```

- [ ] **Step 3: Commit**

```bash
git add docker/php/Dockerfile
git commit -m "chore: add PHP-FPM Dockerfile with SQLite support"
```

### Task 2: Create Caddyfile

**Files:**
- Create: `Caddyfile`

- [ ] **Step 1: Write the Caddyfile**

```caddyfile
:80 {
	root * /srv/public
	php_fastcgi php:9000
	file_server
	encode gzip
}
```

- [ ] **Step 2: Commit**

```bash
git add Caddyfile
git commit -m "chore: add Caddyfile for dev server"
```

### Task 3: Create docker-compose.yml

**Files:**
- Create: `docker-compose.yml`

- [ ] **Step 1: Write docker-compose.yml**

```yaml
services:
  caddy:
    image: caddy:2-alpine
    ports:
      - "${PORT:-9889}:80"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - ./public:/srv/public:ro
      - ./src:/srv/src:ro
      - ./templates:/srv/templates:ro
      - ./config:/srv/config:ro
      - ./vendor:/srv/vendor:ro
      - ./storage:/srv/storage
      - ./waaseyaa.sqlite:/srv/waaseyaa.sqlite
    depends_on:
      - php

  php:
    build: docker/php
    volumes:
      - ./public:/srv/public:ro
      - ./src:/srv/src:ro
      - ./templates:/srv/templates:ro
      - ./config:/srv/config:ro
      - ./vendor:/srv/vendor:ro
      - ./storage:/srv/storage
      - ./waaseyaa.sqlite:/srv/waaseyaa.sqlite
      - ./.env:/srv/.env:ro
    environment:
      - APP_ENV=dev
```

Note: Code volumes are `:ro` (read-only). Only `storage/` and `waaseyaa.sqlite` are writable. The `.env` file is mounted so PHP can read `ANTHROPIC_API_KEY` and other env vars.

- [ ] **Step 2: Commit**

```bash
git add docker-compose.yml
git commit -m "chore: add docker-compose for Caddy + PHP-FPM dev server"
```

### Task 4: Update bin/serve to use Docker

**Files:**
- Modify: `bin/serve`

- [ ] **Step 1: Rewrite bin/serve**

```bash
#!/usr/bin/env bash
# Start the Claudriel dashboard via Docker (Caddy + PHP-FPM)
set -euo pipefail

PORT="${1:-9889}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Ensure data files exist (Docker won't create them)
mkdir -p "${DIR}/storage"
[ -f "${DIR}/waaseyaa.sqlite" ] || touch "${DIR}/waaseyaa.sqlite"
[ -f "${DIR}/.env" ] || { cp "${DIR}/.env.example" "${DIR}/.env" 2>/dev/null && echo "Created .env from .env.example — edit with your ANTHROPIC_API_KEY"; }

echo "Claudriel dashboard → http://localhost:${PORT}"
PORT="${PORT}" exec docker compose -f "${DIR}/docker-compose.yml" up --build
```

- [ ] **Step 2: Verify it starts**

```bash
bin/serve 9889
```

Expected: Docker builds PHP image, starts Caddy+FPM, dashboard accessible at http://localhost:9889

- [ ] **Step 3: Commit**

```bash
git add bin/serve
git commit -m "feat: update bin/serve to use Docker Compose"
```

---

## Chunk 2: Revert SSE Polling Workaround

### Task 5: Restore SSE brief stream in dashboard template

With Caddy+FPM handling concurrency, the SSE EventSource works properly. Revert the polling workaround.

**Files:**
- Modify: `templates/dashboard.twig`

- [ ] **Step 1: Replace polling code with original SSE EventSource**

Find the polling block (starts with `// Poll brief updates`) and replace with:

```javascript
// SSE: Brief updates
var briefSource = new EventSource('/stream/brief');
briefSource.addEventListener('brief-update', function(e) {
    try {
        var data = JSON.parse(e.data);
        if (data.events_html) {
            var eventsEl = document.getElementById('briefEvents');
            if (eventsEl) eventsEl.innerHTML = data.events_html;
        }
        if (data.people_html) {
            var peopleEl = document.getElementById('briefPeople');
            if (peopleEl) peopleEl.innerHTML = data.people_html;
        }
        if (data.commitments_html) {
            var commitmentsEl = document.getElementById('briefCommitments');
            if (commitmentsEl) commitmentsEl.innerHTML = data.commitments_html;
        }
        if (data.drifting_html) {
            var driftingEl = document.getElementById('briefDrifting');
            if (driftingEl) driftingEl.innerHTML = data.drifting_html;
        }
    } catch (err) {
        // Ignore malformed SSE data
    }
});
```

- [ ] **Step 2: Test that the dashboard loads and chat works concurrently**

Open http://localhost:9889, send a chat message. Both should work without blocking.

- [ ] **Step 3: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat: restore SSE brief stream (Docker handles concurrency)"
```

---

## Chunk 3: Symlink Setup Script

### Task 6: Create bin/setup-dev

**Files:**
- Create: `bin/setup-dev`

- [ ] **Step 1: Write the setup script**

```bash
#!/usr/bin/env bash
# One-time setup: symlink code from dev repo into ~/claudriel
set -euo pipefail

DEV_DIR="/home/jones/dev/claudriel"
LIVE_DIR="/home/jones/claudriel"

if [ ! -d "${DEV_DIR}" ]; then
    echo "Error: dev repo not found at ${DEV_DIR}"
    exit 1
fi

echo "Setting up ${LIVE_DIR} with symlinks to ${DEV_DIR}..."

# Back up any existing data files
for data_item in waaseyaa.sqlite .env; do
    if [ -f "${LIVE_DIR}/${data_item}" ] && [ ! -L "${LIVE_DIR}/${data_item}" ]; then
        echo "  Preserving ${data_item}"
        cp "${LIVE_DIR}/${data_item}" "/tmp/claudriel-backup-${data_item}"
    fi
done

if [ -d "${LIVE_DIR}/storage" ] && [ ! -L "${LIVE_DIR}/storage" ]; then
    echo "  Preserving storage/"
    cp -r "${LIVE_DIR}/storage" "/tmp/claudriel-backup-storage"
fi

# Symlink code directories and files
for item in src templates bin config public vendor tests docs tools skills \
            .github .claude composer.json composer.lock phpunit.xml.dist \
            .gitignore CLAUDE.md docker Caddyfile docker-compose.yml \
            .env.example; do
    if [ -e "${DEV_DIR}/${item}" ]; then
        rm -rf "${LIVE_DIR}/${item}"
        ln -s "${DEV_DIR}/${item}" "${LIVE_DIR}/${item}"
        echo "  Linked ${item}"
    fi
done

# Ensure data directories exist
mkdir -p "${LIVE_DIR}/storage"

# Restore backed-up data
for data_item in waaseyaa.sqlite .env; do
    if [ -f "/tmp/claudriel-backup-${data_item}" ]; then
        mv "/tmp/claudriel-backup-${data_item}" "${LIVE_DIR}/${data_item}"
    fi
done

if [ -d "/tmp/claudriel-backup-storage" ]; then
    cp -rn /tmp/claudriel-backup-storage/* "${LIVE_DIR}/storage/" 2>/dev/null || true
    rm -rf /tmp/claudriel-backup-storage
fi

# Create .env if missing
if [ ! -f "${LIVE_DIR}/.env" ]; then
    cp "${DEV_DIR}/.env.example" "${LIVE_DIR}/.env"
    echo "  Created .env from .env.example — edit with your ANTHROPIC_API_KEY"
fi

echo "Done. Run 'bin/serve' to start the dashboard."
```

- [ ] **Step 2: Make executable**

```bash
chmod +x bin/setup-dev
```

- [ ] **Step 3: Commit**

```bash
git add bin/setup-dev
git commit -m "feat: add bin/setup-dev for symlink-based deployment"
```

### Task 7: Run setup-dev and verify

- [ ] **Step 1: Run the setup script**

```bash
bin/setup-dev
```

Expected: All code items become symlinks, data files preserved.

- [ ] **Step 2: Verify symlinks**

```bash
ls -la ~/claudriel/src ~/claudriel/templates ~/claudriel/bin
```

Expected: All point to ~/dev/claudriel/...

- [ ] **Step 3: Verify data preserved**

```bash
ls -la ~/claudriel/waaseyaa.sqlite ~/claudriel/.env ~/claudriel/storage/
```

Expected: Regular files/dirs, not symlinks.

- [ ] **Step 4: Start the dashboard and test**

```bash
cd ~/claudriel && bin/serve 9889
```

Expected: Dashboard loads at http://localhost:9889, chat works, brief SSE streams.

---

## Chunk 4: Cleanup

### Task 8: Remove debug logging from index.php

**Files:**
- Modify: `public/index.php`

- [ ] **Step 1: Check for debug error_log calls**

If `public/index.php` has `error_log('[Claudriel]` lines from this session's debugging, remove them. The file should be:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$kernel = new Waaseyaa\Foundation\Kernel\HttpKernel(dirname(__DIR__));
$kernel->handle();
```

- [ ] **Step 2: Remove debug logging from ChatController and ChatStreamController**

Remove any `error_log('[Claudriel]` lines from:
- `src/Controller/ChatController.php`
- `src/Controller/ChatStreamController.php`

- [ ] **Step 3: Commit**

```bash
git add public/index.php src/Controller/ChatController.php src/Controller/ChatStreamController.php
git commit -m "chore: remove debug logging from controllers"
```

### Task 9: Update .gitignore

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: Ensure docker data is ignored**

Add if not already present:

```gitignore
# Docker
docker-compose.override.yml
```

- [ ] **Step 2: Commit**

```bash
git add .gitignore
git commit -m "chore: update gitignore for Docker overrides"
```

### Task 10: Push to GitHub

- [ ] **Step 1: Push all commits**

```bash
git push origin main
```

- [ ] **Step 2: Sync ~/claudriel**

```bash
cd ~/claudriel && git pull origin main
```

(After setup-dev, this pull updates code via symlinks automatically.)
