# Frontend Editorial Polish — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify Claudriel's frontend under a single "Editorial Operator" dark identity across all pages.

**Architecture:** CSS-first approach. Update design tokens in `base.html.twig`, then apply them across public pages, admin shell, and dashboard. No backend or JS behavior changes. Each PR ships independently.

**Tech Stack:** CSS custom properties, Twig templates, Vue 3 (Nuxt SPA), Google Fonts (Bricolage Grotesque + DM Sans)

**Spec:** `docs/superpowers/specs/2026-03-19-frontend-editorial-polish-design.md`

**Note on testing:** This is a CSS/copy polish project. There are no unit tests for visual styling. Each task verifies by: (1) running existing tests to confirm nothing breaks, (2) visual verification in the browser. The implementer should have the dev server running (`php -S localhost:8081 -t public`) to check each change visually.

---

## File Map

| File | Action | PR | Purpose |
|---|---|---|---|
| `templates/base.html.twig` | Modify | 1 | Design tokens (`:root` CSS variables) |
| `templates/public/homepage.twig` | Modify | 2 | Homepage copy + mockup + value strip |
| `templates/public/signup.twig` | Modify | 2 | Signup copy + feature cards |
| `templates/public/auth-layout.twig` | Modify | 2 | Auth layout token alignment |
| `templates/public/login.twig` | Modify | 2 | Login copy refresh |
| `src/Controller/PublicHomepageController.php` | Modify | 2 | Update headline/subheadline/proof_points |
| `frontend/admin/nuxt.config.ts` | Modify | 3 | Add Google Fonts link tags |
| `frontend/admin/app/components/layout/AdminShell.vue` | Modify | 3 | Full CSS overhaul to dark editorial |
| `templates/dashboard.twig` | Modify | 4 | Editorial greeting + card treatment |

---

## PR 1: Design Tokens

### Task 1: Update `:root` CSS variables in base.html.twig

**Files:**
- Modify: `templates/base.html.twig:13-34`

- [ ] **Step 1: Read current base template**

Read `templates/base.html.twig` to confirm the current `:root` block (lines 13-34).

- [ ] **Step 2: Replace `:root` variables with the full design token set**

Replace the existing `:root` block (lines 13-34) with:

```css
:root {
  /* Font families */
  --font-display: 'Bricolage Grotesque', system-ui, sans-serif;
  --font-body: 'DM Sans', system-ui, sans-serif;

  /* Surfaces */
  --bg-deep: #0a0c10;
  --bg-surface: #131620;
  --bg-elevated: #1a1d2a;
  --bg-hover: #222536;
  --bg-input: rgba(255, 255, 255, 0.06);

  /* Borders */
  --border: rgba(255, 255, 255, 0.06);
  --border-subtle: rgba(255, 255, 255, 0.04);
  --border-emphasis: rgba(255, 255, 255, 0.1);

  /* Text */
  --text-primary: #e8e9ed;
  --text-secondary: #9b9cb5;
  --text-muted: #6b6d82;

  /* Accents */
  --accent-amber: #f0b040;
  --accent-teal: #2dd4bf;
  --accent-blue: #6b9bff;
  --accent-red: #f06060;
  --accent-green: #34d399;
  --accent-purple: #a78bfa;

  /* Spacing */
  --space-xs: 0.25rem;
  --space-sm: 0.5rem;
  --space-md: 1rem;
  --space-lg: 1.5rem;
  --space-xl: 2rem;
  --space-2xl: 3rem;

  /* Radius */
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 14px;
  --radius-pill: 999px;

  /* Accent hover */
  --accent-amber-hover: #d49a2e;
}
```

- [ ] **Step 3: Update body styles to use new tokens**

In the same file, update the `body` rule (around line 37) to use `--bg-deep` instead of the old `--bg-deep` value (was `#0f1117`):

```css
body {
  font-family: var(--font-body);
  background: var(--bg-deep);
  color: var(--text-primary);
  line-height: 1.6;
}
```

- [ ] **Step 4: Update nav styles to use new tokens**

Update `.site-nav` (around line 42) and `.site-nav .brand` (around line 55) to reference new tokens. The base nav uses `--bg-surface` for authenticated pages. Public pages (homepage, auth) already override this to `background: transparent; border-bottom: 0;` in their own `{% block styles %}`, so no additional work is needed there.

```css
.site-nav {
  background: var(--bg-surface);
  padding: 0 1.25rem;
  border-bottom: 1px solid var(--border);
}
```

```css
.site-nav .brand {
  color: var(--text-primary);
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 1.05rem;
  text-decoration: none;
  letter-spacing: -0.02em;
}
```

Update `.nav-logout` to use tokens:

```css
.nav-logout {
  background: none;
  border: 1px solid var(--border);
  color: var(--text-secondary);
  padding: 0.35rem 0.75rem;
  border-radius: var(--radius-sm);
  font-size: 0.8rem;
  cursor: pointer;
  font-family: var(--font-body);
  transition: border-color 0.15s, color 0.15s;
}
.nav-logout:hover {
  border-color: var(--text-muted);
  color: var(--text-primary);
}
```

- [ ] **Step 5: Verify visually**

Run: `php -S localhost:8081 -t public`

Visit `http://localhost:8081/` and `http://localhost:8081/login`. Confirm:
- Dark background (#0a0c10) across all pages
- Bricolage Grotesque headings, DM Sans body text
- Nav bar uses dark surface color
- No broken layouts or missing colors

- [ ] **Step 6: Run existing tests**

Run: `composer test`

Expected: All existing tests pass. No test should depend on CSS values.

- [ ] **Step 7: Commit**

```bash
git add templates/base.html.twig
git commit -m "feat: update design tokens to Editorial Operator system

Replace CSS custom properties with full token set: refined dark surfaces,
typography scale, spacing, border radius, and accent colors."
```

- [ ] **Step 8: Push and create PR**

```bash
git push origin HEAD
```

Create PR targeting `main` with title: `feat: editorial operator design tokens`

---

## PR 2: Public Pages

### Task 2: Update homepage controller data

**Files:**
- Modify: `src/Controller/PublicHomepageController.php:42` (the render context array)

- [ ] **Step 1: Read the controller**

Read `src/Controller/PublicHomepageController.php` to find the context array passed to the template.

- [ ] **Step 2: Update the context array**

Replace the `headline`, `subheadline`, and `proof_points` values:

```php
'headline' => 'Run your operation. Not your inbox.',
'subheadline' => 'Claudriel keeps your commitments, schedule, and client context in one place, so your next move is always obvious.',
'proof_points' => [
    'Commitments extracted from every conversation. Nothing slips.',
    'Your day, structured. Know what matters before your first call.',
    'Context that follows you across clients, projects, and threads.',
],
```

- [ ] **Step 3: Commit**

```bash
git add src/Controller/PublicHomepageController.php
git commit -m "feat: update homepage copy for AI Operator positioning"
```

### Task 3: Rewrite homepage template

**Files:**
- Modify: `templates/public/homepage.twig`

- [ ] **Step 1: Read the current homepage template**

Read `templates/public/homepage.twig` in full.

- [ ] **Step 2: Update the eyebrow text**

Change line 321: `<div class="eyebrow">Public Entry Surface</div>` to:

```html
<div class="eyebrow">For AI Operators</div>
```

- [ ] **Step 3: Rewrite the product mockup frame content**

Replace the `frame-topbar` content (around line 343):

```html
<div class="frame-topbar">
    <div>Claudriel</div>
    <div>Your operating surface</div>
</div>
```

Replace the `frame-sidebar` content (around lines 347-358):

```html
<aside class="frame-sidebar">
    <div class="mini-panel">
        <h3>Commitments</h3>
        <strong>7</strong>
        <div>2 need attention</div>
    </div>
    <div class="mini-panel">
        <h3>Next block</h3>
        <div>Client review</div>
        <div style="color: var(--accent-teal); font-size: 0.82rem;">in 35 min</div>
    </div>
</aside>
```

Replace the `frame-main` content (around lines 361-392) with the editorial operator style:

```html
<div class="frame-main">
    <div style="font-family: var(--font-display); font-size: 1.35rem; font-weight: 600; letter-spacing: -0.02em; line-height: 1.3; margin-bottom: 0.6rem;">
        Good morning.<br>
        <span style="color: var(--text-secondary);">Three things before your first call.</span>
    </div>

    <div style="display: grid; gap: 0.5rem;">
        <div style="display: flex; gap: 0.65rem; align-items: center; padding: 0.65rem 0.8rem; background: rgba(255,255,255,0.03); border-radius: 0.5rem; border-left: 2px solid var(--accent-amber);">
            <span style="font-size: 0.8rem; color: var(--accent-amber); font-weight: 600;">1</span>
            <span style="font-size: 0.85rem; color: #ccd0de;">Review Acme proposal (Sarah flagged timeline)</span>
        </div>
        <div style="display: flex; gap: 0.65rem; align-items: center; padding: 0.65rem 0.8rem; background: rgba(255,255,255,0.03); border-radius: 0.5rem; border-left: 2px solid var(--accent-teal);">
            <span style="font-size: 0.8rem; color: var(--accent-teal); font-weight: 600;">2</span>
            <span style="font-size: 0.85rem; color: #ccd0de;">Two commitments drifting past 48h</span>
        </div>
        <div style="display: flex; gap: 0.65rem; align-items: center; padding: 0.65rem 0.8rem; background: rgba(255,255,255,0.03); border-radius: 0.5rem; border-left: 2px solid var(--accent-blue);">
            <span style="font-size: 0.8rem; color: var(--accent-blue); font-weight: 600;">3</span>
            <span style="font-size: 0.85rem; color: #ccd0de;">Clear block until 2pm: deep work window</span>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Rewrite the value strip**

Replace the value strip section (around lines 399-412):

```html
<section class="value-strip">
    <article class="value-card">
        <h3>Never lose a commitment</h3>
        <p>Every conversation is scanned for promises, deadlines, and follow-ups. Claudriel tracks them so you don't have to.</p>
    </article>
    <article class="value-card">
        <h3>Know your day before it starts</h3>
        <p>A morning brief that surfaces what matters: drifting commitments, upcoming calls, and your next deep work window.</p>
    </article>
    <article class="value-card">
        <h3>Context that compounds</h3>
        <p>Client history, project state, and relationship details, always current, always in reach.</p>
    </article>
</section>
```

- [ ] **Step 5: Update CSS to use design tokens**

In the `{% block styles %}` section, update hardcoded color values to reference the design tokens. Key replacements:
- `rgba(107, 155, 255, 0.22)` stays (gradient background, unique to homepage)
- `.eyebrow` color: already uses `var(--accent-teal)` (good)
- `.hero h1`: update to use `var(--font-display)`
- `.value-card h3`: update to use `var(--font-display)`
- `.mini-panel h3`: update to use `var(--text-muted)`
- `.cta-primary`: keep the amber gradient (already matches spec)
- `.cta-secondary` border: update to use `var(--border-emphasis)`

- [ ] **Step 6: Verify visually**

Visit `http://localhost:8081/`. Confirm:
- "For AI Operators" eyebrow
- New headline and subheadline from controller
- Editorial mockup with numbered priority items and accent left borders
- New value strip copy
- All proof points rendering correctly

- [ ] **Step 7: Commit**

```bash
git add templates/public/homepage.twig
git commit -m "feat: rewrite homepage to Editorial Operator aesthetic

Update eyebrow, product mockup, and value strip. Replace developer jargon
with operator-outcome copy. Add numbered priority items with accent borders."
```

### Task 4: Rewrite signup page

**Files:**
- Modify: `templates/public/signup.twig`

- [ ] **Step 1: Read the current signup template**

Read `templates/public/signup.twig` in full.

- [ ] **Step 2: Update copy**

Replace headline (line 166): `<h1>Your second in command</h1>` with:
```html
<h1>Your operations, handled.</h1>
```

Replace subheadline (line 167): `<p class="lead">...</p>` with:
```html
<p class="lead">An AI-native operating system for people who build AI products. Track commitments, surface priorities, and keep client context current.</p>
```

- [ ] **Step 3: Rewrite feature cards**

Replace the feature grid content (lines 176-192):

```html
<div class="feature-grid">
    <article class="feature-card">
        <div class="feature-icon">&#x2714;</div>
        <h3>Commitment tracking</h3>
        <p>Every email, meeting, and thread scanned for promises and deadlines. Nothing falls through.</p>
    </article>
    <article class="feature-card">
        <div class="feature-icon">&#x2600;</div>
        <h3>Schedule intelligence</h3>
        <p>A daily brief that tells you what matters, what's drifting, and where your next deep work window is.</p>
    </article>
    <article class="feature-card">
        <div class="feature-icon">&#x21C4;</div>
        <h3>Client context</h3>
        <p>Relationship history, project state, and conversation threads, all in one place, always current.</p>
    </article>
</div>
```

- [ ] **Step 4: Update CSS to use tokens**

In the `{% block styles %}` section, update:
- `.feature-card` background: `var(--bg-surface)` border: `1px solid var(--border)`
- `.feature-card h3`: add `font-family: var(--font-display)`
- `.feature-icon` background: use `rgba(240, 176, 64, 0.1)` and border: `rgba(240, 176, 64, 0.2)` to match amber accent
- `.feature-icon` color: `var(--accent-amber)`
- `.waitlist-form input` styles: use `var(--bg-input)`, `var(--border-emphasis)`, focus uses `var(--accent-teal)`

- [ ] **Step 5: Verify visually**

Visit `http://localhost:8081/signup`. Confirm:
- "Your operations, handled." headline
- Updated subheadline
- Three feature cards with operator-outcome copy
- Amber-tinted feature icons
- Form input styling matches dark theme

- [ ] **Step 6: Commit**

```bash
git add templates/public/signup.twig
git commit -m "feat: rewrite signup page for AI Operator audience

Replace technical feature language with operator-outcome copy.
Update feature cards and align CSS with design tokens."
```

### Task 5: Update auth layout and login copy

**Files:**
- Modify: `templates/public/auth-layout.twig`
- Modify: `templates/public/login.twig`
- Review: `templates/public/forgot-password.twig`, `templates/public/reset-password.twig`, `templates/public/check-email.twig`, `templates/public/verification-result.twig`

Note: All auth pages extend `auth-layout.twig`, so CSS token updates in the layout propagate automatically. The other four auth pages (`forgot-password`, `reset-password`, `check-email`, `verification-result`) only need a copy review to confirm their text isn't jarring against the new positioning. Their existing copy is functional and neutral, so changes are optional.

- [ ] **Step 1: Read auth files**

Read `templates/public/auth-layout.twig` and `templates/public/login.twig`. Skim the other four auth templates to confirm their copy doesn't need updating.

- [ ] **Step 2: Update auth-layout CSS to use tokens**

In `auth-layout.twig` `{% block styles %}`, update:
- `.auth-card` background: `var(--bg-surface)`, border: `1px solid var(--border)`
- `.auth-field input`: background `var(--bg-input)`, border `1px solid var(--border-emphasis)`, focus border `var(--accent-teal)`
- `.auth-submit`: keep amber gradient (already correct)
- `.auth-error`: keep (already uses correct dark pattern)
- `.auth-success`: keep (already uses correct dark pattern)
- `.auth-brand h1`: add `font-family: var(--font-display)`
- `.auth-eyebrow`: already uses `var(--accent-teal)` (good)
- `.auth-field label`: color `var(--text-secondary)`

- [ ] **Step 3: Update login copy**

In `login.twig`, update the auth copy blocks:
- `{% block auth_eyebrow %}` → "Welcome back"  (keep, it works)
- `{% block auth_headline %}` → "Your day is waiting." (replace "Pick up where you left off.")
- `{% block auth_subtext %}` → "Commitments, schedule, and context, right where you left them." (replace current)

- [ ] **Step 4: Verify visually**

Visit `http://localhost:8081/login`. Confirm:
- Dark card background with subtle border
- Input fields use dark background with teal focus ring
- Updated copy on the left side
- Amber gradient submit button

- [ ] **Step 5: Commit**

```bash
git add templates/public/auth-layout.twig templates/public/login.twig
git commit -m "feat: align auth pages with editorial design tokens

Update auth layout CSS to use design tokens. Refresh login copy
to match operator positioning."
```

### Task 6: Run full test suite and create PR 2

- [ ] **Step 1: Run tests**

Run: `composer test`

Expected: All tests pass.

- [ ] **Step 2: Commit any remaining changes and push**

```bash
git push origin HEAD
```

---

## PR 3: Admin Shell

### Task 7: Add Google Fonts to Nuxt config

**Files:**
- Modify: `frontend/admin/nuxt.config.ts:24-32`

- [ ] **Step 1: Read nuxt.config.ts**

Read `frontend/admin/nuxt.config.ts`.

- [ ] **Step 2: Add font link tags to app.head**

Add a `link` array inside the existing `app.head` block (after the `meta` array, around line 31):

```ts
link: [
  { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
  { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
  { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap' },
],
```

- [ ] **Step 3: Commit**

```bash
git add frontend/admin/nuxt.config.ts
git commit -m "feat: add Google Fonts to Nuxt SPA head"
```

### Task 8: Overhaul AdminShell.vue CSS

**Files:**
- Modify: `frontend/admin/app/components/layout/AdminShell.vue:86-332`

This is the largest single task. The entire `<style>` block gets replaced.

- [ ] **Step 1: Read AdminShell.vue**

Read `frontend/admin/app/components/layout/AdminShell.vue` in full.

- [ ] **Step 2: Replace `:root` variables**

Replace the `:root` block (lines 87-98) with the full design token set:

```css
:root {
  --sidebar-width: 220px;
  --topbar-height: 48px;

  /* Font families */
  --font-display: 'Bricolage Grotesque', system-ui, sans-serif;
  --font-body: 'DM Sans', system-ui, sans-serif;

  /* Surfaces */
  --bg-deep: #0a0c10;
  --bg-surface: #131620;
  --bg-elevated: #1a1d2a;
  --bg-hover: #222536;
  --bg-input: rgba(255, 255, 255, 0.06);

  /* Borders */
  --border: rgba(255, 255, 255, 0.06);
  --border-subtle: rgba(255, 255, 255, 0.04);
  --border-emphasis: rgba(255, 255, 255, 0.1);

  /* Text */
  --text-primary: #e8e9ed;
  --text-secondary: #9b9cb5;
  --text-muted: #6b6d82;

  /* Accents */
  --accent-amber: #f0b040;
  --accent-amber-hover: #d49a2e;
  --accent-teal: #2dd4bf;
  --accent-blue: #6b9bff;
  --accent-red: #f06060;
  --accent-green: #34d399;
  --accent-purple: #a78bfa;

  /* Spacing */
  --space-xs: 0.25rem;
  --space-sm: 0.5rem;
  --space-md: 1rem;
  --space-lg: 1.5rem;
  --space-xl: 2rem;
  --space-2xl: 3rem;

  /* Radius */
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 14px;
  --radius-pill: 999px;
}
```

- [ ] **Step 3: Update body and shell styles**

```css
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: var(--font-body);
  color: var(--text-primary);
  background: var(--bg-deep);
}

.admin-shell {
  min-height: 100vh;
}
```

- [ ] **Step 4: Update topbar styles**

Replace the topbar styles (lines 112-164):

```css
.topbar {
  height: var(--topbar-height);
  background: var(--bg-surface);
  border-bottom: 1px solid var(--border);
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
}

.topbar-brand {
  color: var(--text-primary);
  text-decoration: none;
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 16px;
  letter-spacing: -0.02em;
  margin-right: auto;
}

.topbar-toggle {
  display: none;
  background: none;
  border: none;
  color: var(--text-primary);
  font-size: 20px;
  cursor: pointer;
  padding: 0 8px;
  margin-right: 8px;
}

.topbar-locale {
  display: inline-flex;
  align-items: center;
}

.topbar-logout {
  border: 1px solid var(--border-emphasis);
  background: transparent;
  color: var(--text-secondary);
  border-radius: var(--radius-sm);
  padding: 6px 10px;
  cursor: pointer;
  font-family: var(--font-body);
  font-size: 0.8rem;
  transition: border-color 0.15s, color 0.15s;
}

.topbar-logout:hover {
  border-color: var(--text-muted);
  color: var(--text-primary);
}

.topbar-locale-select {
  height: 32px;
  border: 1px solid var(--border-emphasis);
  background: transparent;
  color: var(--text-secondary);
  border-radius: var(--radius-sm);
  padding: 0 8px;
  font-size: 12px;
  font-weight: 600;
  font-family: var(--font-body);
}
```

- [ ] **Step 5: Update sidebar and content styles**

```css
.admin-body {
  display: flex;
  min-height: calc(100vh - var(--topbar-height));
}

.sidebar {
  width: var(--sidebar-width);
  background: var(--bg-surface);
  border-right: 1px solid var(--border);
  padding: 16px 0;
}

.sidebar-overlay {
  display: none;
}

.content {
  flex: 1;
  padding: 24px;
  background: var(--bg-deep);
}
```

The sidebar nav items are rendered by `LayoutNavBuilder.vue`. Add styles for nav links inside the sidebar. Read `NavBuilder.vue` to confirm exact class names, then add styles following this pattern:

```css
/* Sidebar nav items — confirm class names from NavBuilder.vue */
.sidebar a,
.sidebar .nav-item {
  display: block;
  padding: 8px 16px;
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 14px;
  font-family: var(--font-body);
  border-radius: 0;
  transition: background 0.15s, color 0.15s;
}
.sidebar a:hover,
.sidebar .nav-item:hover {
  background: var(--bg-elevated);
  color: var(--text-primary);
}
.sidebar a.active,
.sidebar .nav-item.active,
.sidebar .router-link-active {
  background: var(--bg-elevated);
  color: var(--text-primary);
  font-weight: 500;
}
```

- [ ] **Step 6: Update shared component styles**

Replace btn, field, table, and utility styles:

```css
/* Buttons */
.btn {
  display: inline-block;
  padding: 8px 16px;
  border: 1px solid var(--border-emphasis);
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
  color: var(--text-primary);
  cursor: pointer;
  font-size: 14px;
  font-family: var(--font-body);
  text-decoration: none;
  transition: background 0.15s, border-color 0.15s;
}
.btn:hover { background: var(--bg-elevated); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary {
  background: linear-gradient(135deg, #f0b040 0%, #d46337 100%);
  color: #15120e;
  border-color: transparent;
  font-weight: 600;
}
.btn-primary:hover { background: linear-gradient(135deg, #d49a2e 0%, #b8522d 100%); }
.btn-danger { color: var(--accent-red); border-color: var(--accent-red); }
.btn-sm { padding: 4px 10px; font-size: 12px; }

/* Form fields */
.field { margin-bottom: 16px; }
.field-label { display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px; color: var(--text-secondary); }
.field-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border-emphasis);
  border-radius: var(--radius-sm);
  background: var(--bg-input);
  color: var(--text-primary);
  font-size: 14px;
  font-family: var(--font-body);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.field-input:focus { outline: none; border-color: var(--accent-teal); box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.12); }
.field-textarea { resize: vertical; min-height: 100px; }
.field-richtext { min-height: 120px; padding: 8px 12px; border: 1px solid var(--border-emphasis); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-primary); }
.field-description { margin-top: 4px; font-size: 12px; color: var(--text-muted); }
.required { color: var(--accent-red); }

/* Entity table */
.entity-table { width: 100%; border-collapse: collapse; background: var(--bg-surface); border-radius: var(--radius-sm); }
.entity-table th, .entity-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
.entity-table th { font-weight: 600; font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; }
.entity-table th.sortable { cursor: pointer; }
.entity-table .empty { text-align: center; color: var(--text-muted); padding: 40px; }
.entity-table .actions { white-space: nowrap; }
.entity-table .actions > * { margin-right: 4px; }

/* Pagination */
.pagination { display: flex; align-items: center; gap: 12px; margin-top: 16px; font-size: 14px; color: var(--text-muted); }

/* Form actions */
.form-actions { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border); }

/* Status messages */
.loading { padding: 40px; text-align: center; color: var(--text-muted); }
.error { padding: 12px 16px; background: rgba(240, 96, 96, 0.12); color: #fca5a5; border: 1px solid rgba(240, 96, 96, 0.25); border-radius: var(--radius-sm); margin-bottom: 16px; }
.success { padding: 12px 16px; background: rgba(45, 212, 191, 0.12); color: #5eead4; border: 1px solid rgba(45, 212, 191, 0.25); border-radius: var(--radius-sm); margin-bottom: 16px; }

/* Page header */
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.page-header h1 { font-family: var(--font-display); font-size: 1.75rem; font-weight: 700; letter-spacing: -0.03em; }
```

- [ ] **Step 7: Update accessibility and responsive styles**

```css
/* Skip link */
.skip-link {
  position: absolute;
  top: -100%;
  left: 16px;
  background: var(--accent-amber);
  color: #15120e;
  padding: 8px 16px;
  border-radius: 0 0 var(--radius-sm) var(--radius-sm);
  z-index: 1000;
  font-size: 14px;
  text-decoration: none;
}
.skip-link:focus { top: 0; }

.sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}

/* SSE indicator */
.sse-status {
  display: inline-block;
  color: var(--accent-green);
  font-size: 10px;
  margin-left: 8px;
  vertical-align: middle;
  animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

/* Responsive */
@media (max-width: 768px) {
  .topbar-toggle { display: inline-flex; align-items: center; }
  .sidebar {
    position: fixed; top: var(--topbar-height); left: 0; bottom: 0;
    z-index: 50; transform: translateX(-100%); transition: transform 0.2s ease;
  }
  .sidebar--open { transform: translateX(0); }
  .sidebar-overlay {
    display: block; position: fixed; inset: 0; top: var(--topbar-height);
    background: rgba(0, 0, 0, 0.5); z-index: 40;
  }
  .content { padding: 16px; }
  .page-header h1 { font-size: 1.25rem; }
  .entity-table { font-size: 13px; }
  .entity-table th, .entity-table td { padding: 8px; }
}
```

- [ ] **Step 8: Verify visually**

Run: `cd frontend/admin && npm run dev`

Visit `http://localhost:3000/admin/`. Confirm:
- Dark background everywhere
- Dark topbar with Bricolage Grotesque logo (no blue band)
- Dark sidebar with subtle border
- Entity tables with dark rows
- Buttons use amber gradient for primary
- Input fields use dark background with teal focus ring
- No broken layouts or unreadable text

- [ ] **Step 9: Run frontend tests**

Run: `cd frontend/admin && npm run test` (if vitest is configured)

Expected: All tests pass.

- [ ] **Step 10: Commit**

```bash
git add frontend/admin/app/components/layout/AdminShell.vue
git commit -m "feat: overhaul admin shell to dark Editorial Operator theme

Replace light scaffold with dark design tokens. Update topbar, sidebar,
buttons, inputs, tables, and status messages to match editorial identity."
```

- [ ] **Step 11: Run frontend tests and push**

Run: `cd frontend/admin && npm run test` (if vitest is configured)

Expected: All tests pass.

```bash
git push origin HEAD
```

Create PR targeting `main` with title: `feat: admin shell dark editorial theme`

---

## PR 4: Dashboard

### Task 9: Add editorial greeting to dashboard

**Files:**
- Modify: `templates/dashboard.twig`

- [ ] **Step 1: Read the dashboard template**

Read `templates/dashboard.twig`. This is a large file (~107KB). Focus on:
1. The `{% block styles %}` section (CSS)
2. The first content section after `{% block content %}` (where the greeting/brief lives)
3. Where `pending_commitments` and `drifting_commitments` are used

- [ ] **Step 2: Add editorial greeting at the top of the content block**

Find the first content element after `{% block content %}` and add the editorial greeting before existing content. Use Twig logic for time-of-day and commitment count:

```twig
{% set hour = "now"|date("G") %}
{% set greeting = hour < 12 ? 'Good morning.' : (hour < 17 ? 'Good afternoon.' : 'Good evening.') %}
{% set attention_count = (pending_commitments|default([]))|length + (drifting_commitments|default([]))|length %}

<div class="editorial-greeting">
    <h1 class="greeting-headline">{{ greeting }}</h1>
    <p class="greeting-sub">
        {% if attention_count == 0 %}
            You're clear. No commitments need attention.
        {% elseif attention_count == 1 %}
            One thing before your next call.
        {% else %}
            {{ attention_count }} things before your next call.
        {% endif %}
    </p>
</div>
```

- [ ] **Step 3: Add editorial greeting CSS**

In the `{% block styles %}` section, add:

```css
.editorial-greeting {
    margin-bottom: var(--space-xl);
}
.greeting-headline {
    font-family: var(--font-display);
    font-size: clamp(1.75rem, 4vw, 2.5rem);
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1.2;
    color: var(--text-primary);
}
.greeting-sub {
    font-size: 1.1rem;
    color: var(--text-secondary);
    margin-top: var(--space-xs);
}
```

- [ ] **Step 4: Update card and panel styles**

In the `{% block styles %}` section, find existing card/panel styles and update them to the editorial pattern. Add or update these classes:

```css
/* Editorial card pattern for all dashboard cards */
.dashboard-card,
.brief-card,
.commitment-card,
.event-card,
.workspace-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
}

/* Status-colored left borders for commitment items */
.commitment-item--active {
    border-left: 2px solid var(--accent-teal);
}
.commitment-item--drifting {
    border-left: 2px solid var(--accent-amber);
}
.commitment-item--overdue {
    border-left: 2px solid var(--accent-red);
}
.event-item {
    border-left: 2px solid var(--accent-blue);
}

/* Card headings use display font */
.dashboard-card h2,
.dashboard-card h3,
.brief-card h2,
.brief-card h3 {
    font-family: var(--font-display);
    letter-spacing: -0.01em;
}

/* Label text (uppercase utility) */
.card-label {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-muted);
}
```

Note: The exact class names in the dashboard template may differ. The implementer should read the full template to identify the actual CSS classes used for cards, commitment items, and event items, and apply the editorial patterns to those classes. The patterns above show the target aesthetic.

- [ ] **Step 5: Verify visually**

Visit `http://localhost:8081/app` (log in first). Confirm:
- Editorial greeting at the top with correct time-of-day
- Commitment count reflects actual data
- Cards use dark surface with subtle borders
- Commitment items show accent-colored left borders by status
- Display font on headings, body font on content

- [ ] **Step 6: Run tests**

Run: `composer test` or `php vendor/bin/phpunit`

Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat: add editorial greeting and card treatment to dashboard

Add time-of-day greeting with commitment count. Apply editorial card
pattern with status-colored left borders to all dashboard panels."
```

- [ ] **Step 8: Push and create PR**

```bash
git push origin HEAD
```

Create PR targeting `main` with title: `feat: dashboard editorial treatment`

---

## Final Verification

### Task 10: End-to-end visual walkthrough

- [ ] **Step 1: Walk through the full flow**

With both servers running (`php -S localhost:8081 -t public` and `cd frontend/admin && npm run dev`):

1. Visit `http://localhost:8081/` — homepage with operator copy and editorial mockup
2. Click "Join the waitlist" → signup page with operator feature cards
3. Click "Log in" → login page with updated copy and dark form
4. Log in → dashboard with editorial greeting and styled cards
5. Navigate to `/admin/` → dark admin shell with editorial typography

- [ ] **Step 2: Check responsive behavior**

Resize browser to mobile width (<768px) for each page. Confirm:
- Homepage stacks to single column
- Auth pages stack form below brand
- Admin sidebar collapses to hamburger menu
- Dashboard remains readable

- [ ] **Step 3: Run full test suite**

Run both PHP and frontend tests:
```bash
composer test
cd frontend/admin && npm run test
```

Expected: All tests pass.
