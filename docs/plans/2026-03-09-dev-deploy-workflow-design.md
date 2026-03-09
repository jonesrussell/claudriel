# Dev + Deploy Workflow Design

**Date:** 2026-03-09
**Status:** Approved

## Problem

MyClaudia is both a PHP application under active development (`~/dev/myclaudia`) and a daily-use personal operations system. Need to develop it and use it simultaneously without either concern interfering with the other.

Additionally, an existing `~/claudia` installation contains personal context, skills, agents, and rules that must be preserved and migrated.

## Decision

Two copies of the same git repo at different paths, on different branches.

| Location | Branch | Purpose |
|---|---|---|
| `~/dev/myclaudia` | `main` | Development workspace |
| `~/myclaudia` | `release` | Daily-use installed copy |

## Directory Layout (installed copy)

```
~/myclaudia/
├── .claude/                    ← COMMITTED: skills, agents, rules, hooks
│   ├── agents/
│   ├── rules/
│   ├── skills/
│   └── settings.local.json
├── CLAUDE.md                   ← user-facing constitution (personality, skills index)
├── src/                        ← PHP app
├── vendor/                     ← prod deps only
│
│  ── USER DATA (gitignored) ──
├── context/                    ← me.md, commitments, job-search, etc.
├── people/                     ← relationship files
├── projects/                   ← project overviews
├── workspaces/                 ← active workspace content
├── .firecrawl/                 ← cached web research
└── waaseyaa.sqlite             ← database
```

## Two Constitutions

- **`CLAUDE.md`** (dev repo) — developer constitution: layers, specs, GitHub workflow
- **`CLAUDE.user.md`** (dev repo) — template for the user-facing constitution: personality, skills, user context guidance

During deploy, `CLAUDE.user.md` becomes `CLAUDE.md` in the installed copy.

## Git Branch Strategy

```
main       ← active development
release    ← stable, what ~/myclaudia tracks
v0.1, v0.2 ← milestone tags
```

## .gitignore (user data)

```
context/
people/
projects/
.firecrawl/
waaseyaa.sqlite
```

`workspaces/_templates/` is committed; active workspace content is gitignored.

## Migration Plan (from ~/claudia)

1. **Archive**: `tar czf ~/claudia-archive-2026-03-09.tar.gz -C ~ claudia/`
2. **Create release branch**: from `main` in `~/dev/myclaudia`
3. **Clone**: `git clone ~/dev/myclaudia ~/myclaudia && cd ~/myclaudia && git checkout release`
4. **Copy user data**: rsync `context/`, `projects/`, `workspaces/`, `.firecrawl/`, `docs/job-applications/` from `~/claudia/` into `~/myclaudia/`
5. **Migrate skills/agents/rules**: copy `.claude/skills/`, `.claude/agents/`, `.claude/rules/` into the dev repo (committed, deploys with `git pull`)
6. **Create CLAUDE.user.md**: adapt old Claudia personality + skills for new structure
7. **Verify**: `cd ~/myclaudia && claude` — test morning brief, commitment check
8. **Delete old**: `rm -rf ~/claudia` (only after verification)

## Deploy Workflow

```bash
# After feature work in ~/dev/myclaudia:
git checkout release && git merge main && git tag v0.x && git push
git checkout main

# Update installed copy:
cd ~/myclaudia && git pull
```

## What Gets Mined from ~/claudia

Everything migrates. Categories:

- **Personal context** (`context/*`) → `~/myclaudia/context/`
- **Project overviews** (`projects/*`) → `~/myclaudia/projects/`
- **Skills** (`.claude/skills/*`) → committed to repo
- **Agents** (`.claude/agents/*`) → committed to repo
- **Rules** (`.claude/rules/*`) → committed to repo
- **Workspace templates** (`workspaces/_templates/*`) → committed to repo
- **Job search artifacts** (`.firecrawl/*`, `docs/job-applications/*`) → `~/myclaudia/`
- **CLAUDE.md personality** → adapted into `CLAUDE.user.md`
