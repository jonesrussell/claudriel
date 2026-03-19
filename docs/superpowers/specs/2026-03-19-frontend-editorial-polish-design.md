# Frontend Editorial Polish — Design Spec

## Summary

Unify Claudriel's frontend under a single "Editorial Operator" identity: dark, typography-forward, professional. The current frontend has three disconnected visual languages (dark public pages with developer jargon, generic light admin shell, complex dashboard). This spec brings them into one cohesive design system.

## Audience

**Primary:** AI Operators — people who build AI-powered products and services for clients. They use Claudriel as their operational backbone: commitments, schedule, context, and proactive guidance.

**Secondary:** Non-technical entrepreneurs (future customers). The public pages should be credible and professional but the experience prioritizes the operator.

## Positioning

"This is how serious operators run their business: commitments, clients, and context, always current. Built for people who build AI products."

## Aesthetic Direction: Editorial Operator

Dark base with strong typography hierarchy. Large display type, editorial layout. The tool feels like a well-designed publication that happens to be an operations hub. Stripe's dashboard voice meets The Information's layout clarity.

Key characteristics:
- Typography drives hierarchy, not color or borders
- Generous size jumps between heading levels
- Accent-colored left borders for editorial emphasis
- Restrained, confident, not flashy

## Design System

### Typography

**Font family tokens:**

| Token | Value |
|---|---|
| `--font-display` | `'Bricolage Grotesque', system-ui, sans-serif` |
| `--font-body` | `'DM Sans', system-ui, sans-serif` |

**Type scale:**

| Token | Font | Weight | Size | Letter-spacing | Usage |
|---|---|---|---|---|---|
| `--type-display` | var(--font-display) | 700 | `clamp(2.2rem, 5vw, 3.6rem)` | -0.035em | Hero headlines |
| `--type-h1` | var(--font-display) | 700 | 1.75rem | -0.03em | Page titles |
| `--type-h2` | var(--font-display) | 600 | 1.25rem | -0.02em | Section heads |
| `--type-h3` | var(--font-display) | 600 | 1rem | -0.01em | Card/panel titles |
| `--type-body` | var(--font-body) | 400 | 0.95rem | 0 | Body text |
| `--type-small` | var(--font-body) | 400 | 0.85rem | 0 | Secondary text |
| `--type-label` | var(--font-body) | 600 | 0.72rem | 0.1em | Uppercase utility labels |

Weights used: 400 (body), 500 (medium emphasis), 600 (strong), 700 (display only).

### Color Palette

**Surfaces (dark progression):**

| Token | Value | Usage |
|---|---|---|
| `--bg-deep` | `#0a0c10` | Page canvas |
| `--bg-surface` | `#131620` | Cards, sidebar, panels |
| `--bg-elevated` | `#1a1d2a` | Hover states, active items |
| `--bg-hover` | `#222536` | Interactive hover |
| `--bg-input` | `rgba(255, 255, 255, 0.06)` | Form input backgrounds |

**Borders:**

| Token | Value | Usage |
|---|---|---|
| `--border` | `rgba(255, 255, 255, 0.06)` | Default borders |
| `--border-subtle` | `rgba(255, 255, 255, 0.04)` | Quieter separation |
| `--border-emphasis` | `rgba(255, 255, 255, 0.1)` | Input borders, interactive elements |

**Text:**

| Token | Value | Usage |
|---|---|---|
| `--text-primary` | `#e8e9ed` | Primary content |
| `--text-secondary` | `#9b9cb5` | Supporting text |
| `--text-muted` | `#6b6d82` | Labels, metadata |

**Accents:**

| Token | Value | Semantic usage |
|---|---|---|
| `--accent-amber` | `#f0b040` | Primary action, warmth, urgency |
| `--accent-teal` | `#2dd4bf` | Success, active, health |
| `--accent-blue` | `#6b9bff` | Informational, links |
| `--accent-red` | `#f06060` | Danger, drift, overdue |
| `--accent-purple` | `#a78bfa` | AI/intelligence indicators |

**CTA gradient:** `linear-gradient(135deg, #f0b040 0%, #d46337 100%)` with `box-shadow: 0 12px 30px rgba(212, 99, 55, 0.2)`.

### Spacing Scale

| Token | Value |
|---|---|
| `--space-xs` | 0.25rem |
| `--space-sm` | 0.5rem |
| `--space-md` | 1rem |
| `--space-lg` | 1.5rem |
| `--space-xl` | 2rem |
| `--space-2xl` | 3rem |

### Border Radius

| Token | Value | Usage |
|---|---|---|
| `--radius-sm` | 6px | Buttons, inputs, small elements |
| `--radius-md` | 10px | Cards, panels |
| `--radius-lg` | 14px | Large cards, modal containers |
| `--radius-pill` | 999px | Pills, CTAs |

### Component Patterns

**Editorial card:**
```css
background: var(--bg-surface);
border: 1px solid var(--border);
border-radius: var(--radius-md);
padding: var(--space-lg);
```

**Accent-bordered item (numbered list, priority items):**
```css
background: var(--bg-surface);
border-radius: var(--radius-sm);
border-left: 2px solid var(--accent-amber); /* or teal, blue per context */
padding: var(--space-md) var(--space-lg);
```

**Input field:**
```css
background: var(--bg-input);
border: 1px solid var(--border-emphasis);
border-radius: var(--radius-sm);
color: var(--text-primary);
/* Focus: */
border-color: var(--accent-teal);
box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.12);
```

**Primary button:**
```css
background: linear-gradient(135deg, #f0b040 0%, #d46337 100%);
color: #15120e;
border-radius: var(--radius-pill);
box-shadow: 0 12px 30px rgba(212, 99, 55, 0.2);
```

## Page Designs

### Navigation (shared across all pages)

Slim dark nav bar, consistent everywhere:
- Logo (Bricolage Grotesque, 700) left-aligned
- Actions right-aligned (login/signup on public, logout/locale on authenticated)
- Public pages: transparent background, no bottom border
- Authenticated pages: `--bg-surface` background, subtle bottom border
- Height: ~3.25rem (current)

### Homepage

**Structure:** Hero (copy left, product mockup right) + value strip below. Same grid layout as current.

**Copy (final):**
- Eyebrow: "For AI Operators"
- Headline: "Run your operation. Not your inbox."
- Subheadline: "Claudriel keeps your commitments, schedule, and client context in one place, so your next move is always obvious."
- Primary CTA: "Join the waitlist"
- Secondary CTA: "Log in"

**Proof points (final):**
- "Commitments extracted from every conversation. Nothing slips."
- "Your day, structured. Know what matters before your first call."
- "Context that follows you across clients, projects, and threads."

**Product mockup:** Update the frame content to show the Editorial Operator aesthetic:
- Large greeting type: "Good morning. Three things before your first call."
- Numbered priority items with accent-colored left borders
- Replaces current "operating surface" mockup content

**Value strip (final, three cards):**
- **"Never lose a commitment"** — "Every conversation is scanned for promises, deadlines, and follow-ups. Claudriel tracks them so you don't have to."
- **"Know your day before it starts"** — "A morning brief that surfaces what matters: drifting commitments, upcoming calls, and your next deep work window."
- **"Context that compounds"** — "Client history, project state, and relationship details, always current, always in reach."

### Signup Page

**Structure:** Centered layout with badge, headline, email form, feature grid below.

**Copy (final):**
- Badge: "Launching Soon"
- Headline: "Your operations, handled."
- Subheadline: "An AI-native operating system for people who build AI products. Track commitments, surface priorities, and keep client context current."

**Feature cards (final):**
- **"Commitment tracking"** — "Every email, meeting, and thread scanned for promises and deadlines. Nothing falls through."
- **"Schedule intelligence"** — "A daily brief that tells you what matters, what's drifting, and where your next deep work window is."
- **"Client context"** — "Relationship history, project state, and conversation threads, all in one place, always current."

### Auth Pages (login, forgot-password, reset-password, check-email, verification-result)

**Changes:**
- Inherit updated design tokens (darker base, refined typography)
- Left-side copy refresh to match new positioning voice
- Form inputs, buttons, error/success states already use the right patterns, just need token updates
- No structural changes

### Admin Shell (Nuxt SPA: AdminShell.vue)

**CSS variable replacement:**

| Current | New |
|---|---|
| `--color-bg: #f5f5f5` | `--bg-deep` (#0a0c10) |
| `--color-surface: #fff` | `--bg-surface` (#131620) |
| `--color-primary: #2563eb` | `--accent-amber` (#f0b040) |
| `--color-primary-hover: #1d4ed8` | `#d49a2e` (darkened amber) |
| `--color-danger: #dc2626` | `--accent-red` (#f06060) |
| `--color-text: #1f2937` | `--text-primary` (#e8e9ed) |
| `--color-muted: #6b7280` | `--text-muted` (#6b6d82) |
| `--color-border: #e5e7eb` | `--border` (rgba(255,255,255,0.06)) |

**Topbar:** Dark `--bg-surface`, no more blue band. Logo in white, actions styled to match public nav.

**Sidebar:** Dark `--bg-surface`, subtle right border. Nav items: `--text-secondary` default, `--text-primary` + `--bg-elevated` on hover/active.

**Body font:** Replace `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif` with `var(--font-body)` (DM Sans). Headings use `var(--font-display)` (Bricolage Grotesque).

**Component reskinning:**
- `.btn-primary`: amber gradient (matching public CTA)
- `.field-input`: dark background, subtle border, teal focus ring
- `.entity-table`: dark rows, subtle borders, muted header text
- `.error` / `.success`: dark-background alert style (matching auth pages)
- `.page-header h1`: Bricolage Grotesque

### Dashboard (Twig template)

**Editorial greeting:**
- Large display type greeting: "Good morning." / "Good afternoon." / "Good evening." (based on server time, already available in the dashboard controller)
- Followed by secondary line in `--text-secondary`: "{N} things before your next call." where N = count of pending commitments + drifting commitments. Fallbacks: if N=0, "You're clear. No commitments need attention." If N=1, "One thing before your next call."
- This is a CSS/copy change to the existing brief section, not new JS logic. The counts already exist in the template variables (`pending_commitments`, `drifting_commitments`).

**Status-to-color mapping (single source of truth):**

| Status | Color token | Left border | Usage |
|---|---|---|---|
| Active / on track | `--accent-teal` | 2px solid | Healthy commitments, active items |
| Needs attention / drifting | `--accent-amber` | 2px solid | Commitments past 48h without update |
| Overdue / failed | `--accent-red` | 2px solid | Missed deadlines, errors |
| Informational / neutral | `--accent-blue` | 2px solid | Events, schedule blocks, FYI items |
| AI-generated / inferred | `--accent-purple` | 2px solid | AI suggestions, extracted data |

**Panels and cards:**
- All cards use the editorial card pattern (dark surface, subtle border, generous padding)
- Commitment cards: left-border accent per status mapping above
- Event timeline: left-border accent treatment (blue for scheduled, amber for imminent)
- Workspace panels: same card pattern

**What stays untouched:**
- All business logic, conditionals, data loops
- JavaScript behavior (SSE streaming, chat, brief loading)
- Template structure and section ordering

## Out of Scope

- Audit views (`templates/audit/*`)
- Governance views (`templates/governance/*`)
- AI views (`templates/ai/*`)
- Platform observability (`templates/platform/*`)
- Telescope Nuxt components (ContextHeatmap, DriftScoreChart, ValidationReportCard, EventStreamViewer)
- Backend controllers, routing, or data changes
- New features or functionality
- Schema form widget internals (beyond basic dark input styling)
- JavaScript behavior changes

## Delivery Order

Each step ships as an independent PR:

1. **Design tokens** — Update `base.html.twig` CSS custom properties. Add type scale, spacing scale, refined color palette. This is the foundation everything else depends on.
2. **Public pages** — Homepage copy + mockup rewrite, signup rewrite, auth page token inheritance and copy refresh.
3. **Admin shell** — `AdminShell.vue` CSS overhaul: dark theme, new font stack, component reskinning.
4. **Dashboard** — Editorial greeting, card treatment, accent-border patterns. Twig template CSS updates only.

## Technical Notes

### Token sharing between Twig and Nuxt

Design tokens are defined as CSS custom properties in `base.html.twig` `:root`. Twig pages inherit these directly. The admin shell (Nuxt SPA) loads independently and does not inherit from `base.html.twig`.

**Approach:** Duplicate the full `:root` token block in `AdminShell.vue`'s `<style>` section. This is the simplest approach given the SPA's independent loading. The token set is small (~40 variables) and changes infrequently.

**Tokens to duplicate:** All variables in the following groups:
- Font families (`--font-display`, `--font-body`)
- Surfaces (`--bg-deep` through `--bg-input`)
- Borders (`--border`, `--border-subtle`, `--border-emphasis`)
- Text (`--text-primary`, `--text-secondary`, `--text-muted`)
- Accents (`--accent-amber` through `--accent-purple`)
- Spacing (`--space-xs` through `--space-2xl`)
- Radius (`--radius-sm` through `--radius-pill`)

When PR #1 (design tokens) lands, PR #3 (admin shell) copies the final `:root` block from `base.html.twig` into `AdminShell.vue`.

### Font loading for the Nuxt SPA

The Google Fonts import (Bricolage Grotesque + DM Sans) is already in `base.html.twig` via `<link>` tags. The Nuxt SPA needs the same fonts. Add the Google Fonts `<link>` tags in `nuxt.config.ts` under `app.head.link`:

```ts
app: {
  head: {
    link: [
      { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
      { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
      { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap' }
    ]
  }
}
```

### Dashboard template

The dashboard template is ~107KB. Changes are CSS-only (no structural modifications), applied through the inherited design tokens and targeted class styling in the `{% block styles %}` section.
