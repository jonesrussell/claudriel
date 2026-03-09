# Dev + Deploy Workflow Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Set up a dev/deploy split so MyClaudia can be developed at `~/dev/myclaudia` and used daily at `~/myclaudia`, with the old `~/claudia` archived and mined.

**Architecture:** Two clones of the same repo on different branches (`main` for dev, `release` for daily use). User data lives in gitignored directories. Skills/agents/rules are committed to the repo and deploy via `git pull`.

**Tech Stack:** Git branches, shell scripts, tar archives

---

### Task 1: Archive ~/claudia

**Files:**
- Create: `~/claudia-archive-2026-03-09.tar.gz`

**Step 1: Create the archive**

Run:
```bash
tar czf ~/claudia-archive-2026-03-09.tar.gz -C ~ claudia/
```
Expected: Archive created, ~2-3MB

**Step 2: Verify archive contents**

Run:
```bash
tar tzf ~/claudia-archive-2026-03-09.tar.gz | head -20
```
Expected: Lists `claudia/` files including `CLAUDE.md`, `context/`, `.claude/skills/`

**Step 3: Commit (n/a — no repo changes)**

---

### Task 2: Update .gitignore for user data directories

**Files:**
- Modify: `.gitignore`

**Step 1: Add user data paths to .gitignore**

Append to `.gitignore`:
```
# User data (per-install, not committed)
context/
people/
projects/
.firecrawl/
docs/job-applications/
```

**Step 2: Verify nothing in those dirs is currently tracked**

Run:
```bash
git ls-files context/ people/ projects/ .firecrawl/ docs/job-applications/
```
Expected: Empty output (nothing tracked)

**Step 3: Commit**

```bash
git add .gitignore
git commit -m "chore: gitignore user data directories for dev/deploy split"
```

---

### Task 3: Migrate skills from ~/claudia into the repo

**Files:**
- Create: `.claude/skills/` (entire directory tree from old claudia)
- Create: `.claude/agents/` (from old claudia)
- Create: `.claude/rules/` (from old claudia)

**Step 1: Copy skills, agents, and rules**

```bash
cp -r ~/claudia/.claude/skills/ .claude/skills/
cp -r ~/claudia/.claude/agents/ .claude/agents/
cp -r ~/claudia/.claude/rules/ .claude/rules/
```

**Step 2: Verify the copy**

Run:
```bash
ls .claude/skills/ | head -10
ls .claude/agents/
ls .claude/rules/
```
Expected: Skills directories (morning-brief, inbox-check, etc.), agents (document-archivist, research-scout, etc.), rules (claudia-principles, trust-north-star, etc.)

**Step 3: Remove skills that reference non-existent infrastructure**

Review each skill. Remove or stub any that depend on:
- `claudia-memory` daemon MCP tools (`memory.recall`, `memory.remember`, etc.)
- `claudia` npm CLI binary
- Obsidian vault sync (`~/.claudia/vault/`)
- Rube/Composio MCP

These features don't exist in MyClaudia yet. Skills that reference them should be marked with a `# TODO: requires memory daemon` comment at the top rather than deleted, so we know what to build later.

**Step 4: Commit**

```bash
git add .claude/skills/ .claude/agents/ .claude/rules/
git commit -m "feat: migrate skills, agents, and rules from claudia"
```

---

### Task 4: Copy workspace templates into the repo

**Files:**
- Create: `workspaces/_templates/` (from old claudia)

**Step 1: Copy templates**

```bash
mkdir -p workspaces
cp -r ~/claudia/workspaces/_templates/ workspaces/_templates/
```

**Step 2: Add gitignore for active workspace content but allow templates**

Add to `.gitignore`:
```
workspaces/*
!workspaces/_templates/
```

**Step 3: Commit**

```bash
git add workspaces/_templates/ .gitignore
git commit -m "feat: add workspace templates from claudia"
```

---

### Task 5: Create CLAUDE.user.md (user-facing constitution)

**Files:**
- Create: `CLAUDE.user.md`

**Step 1: Create the user-facing CLAUDE.md template**

This file adapts `~/claudia/CLAUDE.md` for the MyClaudia structure. Key changes:
- Remove references to `claudia-memory` daemon, Obsidian vault, Rube
- Update file paths to match new structure
- Keep personality, communication style, core behaviors
- Reference the PHP app's commands (`myclaudia:brief`, `myclaudia:commitments`)
- Add note that this file is deployed from `CLAUDE.user.md` in the dev repo

Content: Adapt from `~/claudia/CLAUDE.md`, keeping sections:
- Who I Am
- Primary Mission
- How I Carry Myself (communication style)
- Core Behaviors (safety, relationships, commitment tracking, pattern recognition)
- Skills reference
- File Locations (updated paths)
- What Stays Human Judgment

Remove sections:
- Integrations (Rube, memory daemon, Obsidian — not yet available)
- Google Integration Setup (use MCP servers directly)
- My Team (agent dispatch — simplify to just using Claude Code agents)

Add sections:
- CLI Commands (`myclaudia:brief`, `myclaudia:commitments`)
- How This Is Deployed (note about dev repo origin)

**Step 2: Commit**

```bash
git add CLAUDE.user.md
git commit -m "feat: add user-facing CLAUDE.md template"
```

---

### Task 6: Create release branch and deploy script

**Files:**
- Create: `bin/deploy`
- Create: `bin/setup-install` (first-time setup for ~/myclaudia)

**Step 1: Create the deploy script**

`bin/deploy`:
```bash
#!/usr/bin/env bash
set -euo pipefail

# Deploy current main to release branch
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
INSTALL_DIR="$HOME/myclaudia"

cd "$REPO_DIR"

# Ensure we're on main
CURRENT=$(git branch --show-current)
if [ "$CURRENT" != "main" ]; then
  echo "ERROR: Must be on main branch (currently on $CURRENT)"
  exit 1
fi

# Merge main into release
git checkout release
git merge main --no-edit
git checkout main

echo "Release branch updated. To update your install:"
echo "  cd $INSTALL_DIR && git pull"
```

**Step 2: Create the first-time setup script**

`bin/setup-install`:
```bash
#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
INSTALL_DIR="$HOME/myclaudia"

if [ -d "$INSTALL_DIR" ]; then
  echo "ERROR: $INSTALL_DIR already exists"
  exit 1
fi

# Clone and checkout release
git clone "$REPO_DIR" "$INSTALL_DIR"
cd "$INSTALL_DIR"
git checkout release

# Create user data directories
mkdir -p context people projects .firecrawl

# Copy CLAUDE.user.md as the active CLAUDE.md
cp CLAUDE.user.md CLAUDE.md

# Install prod dependencies
composer install --no-dev --no-interaction 2>/dev/null || echo "Note: composer not available or no deps to install"

echo ""
echo "MyClaudia installed at $INSTALL_DIR"
echo ""
echo "Next steps:"
echo "  1. Copy your user data into $INSTALL_DIR/context/"
echo "  2. cd $INSTALL_DIR && claude"
echo ""
```

**Step 3: Make scripts executable and commit**

```bash
chmod +x bin/deploy bin/setup-install
git add bin/deploy bin/setup-install
git commit -m "feat: add deploy and setup-install scripts"
```

---

### Task 7: Create the release branch

**Step 1: Create and push the release branch**

```bash
git checkout -b release
git checkout main
```

**Step 2: Verify**

Run:
```bash
git branch
```
Expected: Shows `main` (active) and `release`

**Step 3: Commit (n/a — branch creation only)**

---

### Task 8: Run setup-install to create ~/myclaudia

**Step 1: Deploy main to release**

```bash
bin/deploy
```

**Step 2: Run setup**

```bash
bin/setup-install
```
Expected: Clones to `~/myclaudia`, checks out release branch, creates data dirs

**Step 3: Verify the install**

```bash
ls ~/myclaudia/
cat ~/myclaudia/CLAUDE.md | head -5
git -C ~/myclaudia branch --show-current
```
Expected: Files present, user-facing CLAUDE.md, on `release` branch

---

### Task 9: Migrate user data from ~/claudia to ~/myclaudia

**Step 1: Copy user data**

```bash
cp -r ~/claudia/context/* ~/myclaudia/context/
cp -r ~/claudia/projects/* ~/myclaudia/projects/ 2>/dev/null || true
cp -r ~/claudia/.firecrawl/* ~/myclaudia/.firecrawl/ 2>/dev/null || true
mkdir -p ~/myclaudia/docs/job-applications
cp -r ~/claudia/docs/job-applications/* ~/myclaudia/docs/job-applications/ 2>/dev/null || true
```

**Step 2: Verify key files exist**

```bash
cat ~/myclaudia/context/me.md | head -5
ls ~/myclaudia/context/
ls ~/myclaudia/projects/
```
Expected: `me.md` starts with `# Russell`, context files present, project dirs present

**Step 3: Commit (n/a — gitignored data)**

---

### Task 10: Verify daily-use workflow

**Step 1: Test that claude starts correctly in ~/myclaudia**

```bash
cd ~/myclaudia && claude --print "What is the first line of context/me.md?"
```
Expected: Responds with info from `me.md`

**Step 2: Test that dev repo is unaffected**

```bash
cd ~/dev/myclaudia && git status
```
Expected: Clean working tree, on `main` branch

**Step 3: Test deploy cycle**

Make a trivial change in `~/dev/myclaudia`, commit, run `bin/deploy`, then `cd ~/myclaudia && git pull`. Verify the change appears.

---

### Task 11: Delete ~/claudia (user confirmation required)

**Step 1: Confirm archive exists and is valid**

```bash
tar tzf ~/claudia-archive-2026-03-09.tar.gz | wc -l
```
Expected: 100+ files listed

**Step 2: Confirm ~/myclaudia has all needed data**

```bash
diff <(ls ~/claudia/context/) <(ls ~/myclaudia/context/)
```
Expected: Identical or ~/myclaudia has everything ~/claudia had

**Step 3: Ask user for confirmation, then delete**

```bash
rm -rf ~/claudia
```

⚠️ **Only after user explicitly confirms.**
