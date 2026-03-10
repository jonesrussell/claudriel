# Day Brief Redesign — Chat-First with Collapsible Context Panel

**Date:** 2026-03-10
**Status:** Approved
**Milestone:** v0.2 — Daily Use

## Problem

The prototype brief dumps all data (23 events, 10 "people," raw event lists) without categorization, deduplication, or prioritization. Calendar events appear twice. 8 of 10 people are noreply bots. The brief is a wall of noise, not a useful daily tool.

## Design Principles

1. **Chat-first** — the brief is a conversation, not a dashboard
2. **Schedule as backbone** — calendar orients the day, everything else layers on
3. **Categorize, don't dump** — events are grouped by purpose (job hunt, people, notifications), not by source
4. **Deduplicate system-wide** — same event ingested twice = shown once
5. **People are real** — filter automated senders, distinguish contacts from creators

## Architecture: Two Zones

### Zone 1: Collapsible Context Panel (left sidebar)

A slim (~300px) sidebar showing today's snapshot. Renders once on page load from `/brief` JSON. Not live-synced.

**Sections (top to bottom):**

1. **Schedule timeline** — vertical timeline of today's events (time + title). Current/next event highlighted. Collapses to "+N more" if >4 events.
2. **Action items** — pending commitments with due indicators (today = amber, overdue = red, drifting = pulsing). Count badge.
3. **At a glance** — 2x2 counter grid: job alerts, unread messages, due today, drifting commitments. Color-coded.

**Behavior:**
- Open by default on desktop (>1024px), closed on mobile
- Toggle persists in localStorage
- Collapsed = thin icon rail (~48px) with counter badges
- Clicking a section pre-fills a chat prompt (e.g., clicking "4 Job Alerts" types "Show me today's job listings")
- No People section, no event list, no navigation tabs

### Zone 2: Chat (primary, right)

Full conversational interface. Expands to full width when panel is collapsed.

**Morning brief flow:**
1. On page load, check if brief has been sent today
2. If not, auto-generate morning brief as the first message
3. Brief message contains rich cards (schedule, job hunt, people, commitments)
4. User responds naturally from there

**Rich card types:**
- **Schedule card** — today's timeline, compact format
- **Job hunt card** — aggregated listings with source, title, key details
- **People card** — real contacts who reached out, with context
- **Commitment card** — action items with due dates and status
- **Creator card** — lighter treatment for followed creators' new content
- **Notification summary** — collapsed count with actionable items called out

**Conversation capabilities:**
- Drill into any card ("Tell me more about the Wiraa position")
- Ask about people ("What did Chris say?")
- Re-query for updates ("Any new jobs since this morning?")
- Future: take action ("Reschedule my afternoon")

**Existing infrastructure preserved:** ChatController, ChatStreamController, SSE streaming, session management all carry forward. Changes are auto-trigger morning brief, card rendering, and brief data as AI context.

## Data Model Changes

### Event Deduplication

- Add `content_hash` field (string) to McEvent
- Add `content_hash` to McEvent's `entityKeys` array so the storage layer enforces uniqueness
- Computed from `source + type + normalized payload key fields`
- Calendar events: hash on `event_title + start_time + calendar_id`
- Emails: hash on `message_id` (Gmail IDs are already unique)
- Relationship to `trace_id`: `trace_id` is unique per ingestion run (identifies the fetch cycle); `content_hash` is unique per logical event (identifies duplicate content across runs). Both coexist.
- On ingestion, check for existing `content_hash` before inserting; skip if duplicate
- **Migration:** Compute `content_hash` for all existing McEvent records. Where duplicates are found, keep the earliest record and remove the rest.

### Event Categorization

- Add `category` field (string) to McEvent: `schedule`, `job_hunt`, `people`, `creator`, `notification`
- **Important: all email-based events arrive via `source=gmail`.** There are no separate Indeed, Glassdoor, or LinkedIn ingestion sources. Job alerts, notifications, and personal messages all come through Gmail. Google Calendar events arrive via `source=google-calendar`.
- Classify on ingestion using source + sender/content rules:
  - `source=google-calendar` → `schedule`
  - `source=gmail` + sender matches known job alert patterns (`indeed.com`, `glassdoor.com`, `linkedin.com/job`, `twinehq.com`) → `job_hunt`
  - `source=gmail` + sender Person has `tier=contact` → `people`
  - `source=gmail` + sender Person has `tier=creator` → `creator`
  - Everything else → `notification`
- The categorization logic lives in `src/Ingestion/` as an `EventCategorizer` class (Layer 2), called during event handling
- Assembler groups by category instead of by source
- **Migration:** Existing McEvent records get `category` computed retroactively using the same rules. Default for records that can't be classified: `notification`.

### Person Tiering

- Add `tier` field (string) to Person entity: `contact`, `creator`, `automated`
- Auto-classify on ingestion:
  - Email matches noreply patterns (`noreply@`, `alert@`, `digest@`, `jobalerts-noreply@`) → `automated`
  - Sender domain is a known creator platform (patreon.com) → `creator`
  - Default → `contact`
- `DayBriefAssembler` filters by tier when building People data
- `automated` tier people are hidden from all People-facing UI
- **Migration:** Existing Person records get `tier` computed retroactively. Default: `contact`. Records matching noreply patterns get `automated`.

### Actionability Detection

- Notifications get an `actionable` boolean flag on McEvent
- Implemented as a new `ActionabilityStep` in `src/Pipeline/` (implements `PipelineStepInterface`, Layer 2)
- Classifies: "Your PAT expired" = actionable, "Copilot CLI is now GA" = informational
- Only actionable notifications are surfaced prominently
- **Migration:** Existing notification-category events default to `actionable=false`. Can be backfilled by running the pipeline step retroactively.

### DayBriefAssembler Changes

The `assemble()` method return shape changes. This is a **breaking change** affecting three consumers:
- `DayBriefController::show()` (JSON API + Twig rendering)
- `ChatSystemPromptBuilder` (chat AI context)
- `BriefCommand` (CLI output)

All three must be updated in issue #4.

**New return shape:**

```php
[
    'schedule' => [           // McEvent[] where category=schedule, sorted by occurred_at
        ['title' => '...', 'start_time' => '...', 'end_time' => '...', 'calendar_id' => '...'],
    ],
    'job_hunt' => [           // McEvent[] where category=job_hunt
        ['title' => '...', 'source_name' => '...', 'company' => '...', 'details' => '...'],
    ],
    'people' => [             // McEvent[] where category=people, with Person data
        ['person' => Person, 'event' => McEvent, 'summary' => '...'],
    ],
    'creators' => [           // McEvent[] where category=creator, with Person data
        ['person' => Person, 'event' => McEvent, 'summary' => '...'],
    ],
    'notifications' => [      // McEvent[] where category=notification
        ['event' => McEvent, 'actionable' => bool],
    ],
    'commitments' => [
        'pending' => Commitment[],    // status=pending, confidence >= 0.7
        'drifting' => Commitment[],   // from DriftDetector
    ],
    'counts' => [
        'job_alerts' => int,
        'messages' => int,
        'due_today' => int,
        'drifting' => int,
    ],
    'generated_at' => string,         // ISO 8601 timestamp
]
```

### CLI Brief Command

`BriefCommand` (`claudriel:brief`) also consumes `DayBriefAssembler` output. It will be updated in issue #4 to render the new categorized format. The CLI output will mirror the chat brief structure: schedule first, then job hunt, people, commitments, and a notification summary.

## Job Hunt Section

A dedicated aggregation across job-related email senders (Indeed, LinkedIn Job Alerts, Glassdoor, Twine), all arriving via the Gmail ingestion source. The brief treats job hunting as a first-class mode, not just a category of email.

The job hunt card surfaces: listing title, company, source, and key details (remote/location, role level). Multiple listings are shown in a compact list within the card.

## GitHub Issues

All work under milestone **v0.2 — Daily Use**.

### Backend (issues 1-4)

| Issue | Title | Dependencies |
|---|---|---|
| 1 | Event deduplication via content hash | None |
| 2 | Person tiering (contact/creator/automated) | None |
| 3 | Event categorization (schedule/job_hunt/people/creator/notification) | None |
| 4 | Redesign DayBriefAssembler for categorized output | 1, 2, 3 |

### Frontend/Integration (issues 5-8)

| Issue | Title | Dependencies |
|---|---|---|
| 5 | Chat-first page layout with collapsible context panel | 4 |
| 6 | Rich card rendering in chat | 4 |
| 7 | Auto-trigger morning brief in chat | 4, 6 |
| 8 | Dedicated job hunt section | 3, 6 |

Issues 1-3 can be done in parallel. 4 depends on 1-3. 5-8 depend on 4 (and some on each other as noted).
