# Public Auth Shell Design

## Summary

Introduce a shared Twig layout for all public auth pages (login, signup, forgot-password, check-email, reset-password, reset-password-complete, verification-result). Replace the current mix of bare HTML and bespoke styling with a consistent split-layout design that matches the marketing homepage visual system.

Covers GitHub issues #146 (shared auth shell) and #147 (cross-links, autofill, visual polish).

## Problem

Six of seven public auth templates are bare unstyled HTML. The seventh (signup) uses an incompatible warm/serif design system. None extend `base.html.twig`. No cross-links exist between auth pages. Most forms lack autocomplete attributes.

## Template Architecture

```
base.html.twig
  └── public/auth-layout.twig (new)
        ├── public/login.twig
        ├── public/signup.twig
        ├── public/forgot-password.twig
        ├── public/check-email.twig
        ├── public/reset-password.twig
        ├── public/reset-password-complete.twig
        └── public/verification-result.twig
```

`auth-layout.twig` extends `base.html.twig` and defines:

- `{% block styles %}` with split-grid CSS using homepage design tokens (must override `.site-nav` background to transparent, since `base.html.twig` defaults to `var(--bg-surface)`)
- `{% block content %}` overridden to produce the split grid markup
- Left panel blocks: `{% block auth_eyebrow %}`, `{% block auth_headline %}`, `{% block auth_subtext %}`
- Right panel: form card wrapper with `{% block auth_form %}` and `{% block auth_links %}`

Each child template fills those blocks plus `{% block title %}`. No standalone HTML documents remain.

## Split Layout

- **Grid**: `grid-template-columns: 1fr 1fr` on desktop
- **Left panel**: Dark gradient background with teal/blue radial accents (matching homepage). Eyebrow with teal accent line, headline in Bricolage Grotesque, subtext in DM Sans.
- **Right panel**: Centered form card with `rgba(255, 255, 255, 0.04)` background, `rgba(255, 255, 255, 0.06)` border, `1.2rem` border radius. Matches homepage proof-chip/value-card aesthetic.
- **Form inputs**: Dark background (`rgba(255, 255, 255, 0.06)`), light border, rounded, consistent padding. Focus state with teal border glow.
- **Submit button**: `cta-primary` gradient (amber-to-orange), full width within the form card.
- **Cross-links**: Below the form card, muted text (`var(--text-secondary)`), teal accent link color.
- **Breakpoint**: 768px collapses to single column. Left panel becomes compact header above the form.
- **Nav bar**: Inherited from `base.html.twig`. `auth-layout.twig` overrides `.site-nav` background to `transparent` (base uses `var(--bg-surface)`).

## Per-Page Content

| Page | Title | Eyebrow | Headline | Subtext |
|---|---|---|---|---|
| login | Log In | Welcome back | Pick up where you left off. | Your schedule, commitments, and workspace are waiting. |
| signup | Sign Up | Get started | Run your day before it runs you. | Create your account to start. |
| forgot-password | Forgot Password | Account recovery | Reset your password. | We'll send a link to your email. |
| check-email | Check Your Email | Check your inbox | We sent you a link. | Follow the link in your email to continue. |
| reset-password | Reset Password | Almost there | Choose a new password. | Enter your new password below. |
| reset-password-complete (status=complete) | Password Reset | Done | Password updated. | You can now log in with your new password. |
| reset-password-complete (status=invalid) | Password Reset | Something went wrong | That reset link is invalid or expired. | Request a new one to try again. |
| verification-result (status=verified) | Email Verified | You're verified | Account ready. | Your tenant and workspace have been set up. |
| verification-result (status=invalid) | Verification Failed | Something went wrong | That verification link is invalid or expired. | Try signing up again. |
| verification-result (status=pending) | Verification Pending | Almost there | Check your email for the verification link. | We sent it when you signed up. |

Note: `check-email.twig` is shared by both the signup verification and password reset flows. The generic copy ("We sent you a link") works for both. Controllers pass only `email` as context, so no conditional is needed.

Note: `verification-result.twig` receives optional `tenant`, `workspace`, and `workspace_sidecar_state` variables from the onboarding flow. These are preserved but not displayed in the new design (they were debug output). The conditional logic keys on the `status` variable.

## Cross-Links

| Page | Links |
|---|---|
| login | "Don't have an account? Sign up" / "Forgot your password?" (below password field) |
| signup | "Already have an account? Log in" |
| forgot-password | "Back to log in" |
| check-email | "Back to log in" |
| reset-password | "Back to log in" |
| reset-password-complete | "Log in" |
| verification-result | "Log in" / "Sign up" (conditional) |

Inline text links rendered in `{% block auth_links %}` (below the form card), except "Forgot your password?" which goes inside `{% block auth_form %}` directly below the password input and above the submit button.

## Autocomplete Attributes

| Input | Attribute |
|---|---|
| email (all forms) | `autocomplete="email"` |
| password (login) | `autocomplete="current-password"` |
| password (signup, reset) | `autocomplete="new-password"` |
| name (signup) | `autocomplete="name"` |

## Files Changed

| File | Action |
|---|---|
| `templates/public/auth-layout.twig` | Create (shared split-layout wrapper) |
| `templates/public/login.twig` | Rewrite (extend auth-layout, add form + cross-links) |
| `templates/public/signup.twig` | Rewrite (extend auth-layout, drop bespoke styles) |
| `templates/public/forgot-password.twig` | Rewrite (extend auth-layout) |
| `templates/public/check-email.twig` | Rewrite (extend auth-layout, message-only) |
| `templates/public/reset-password.twig` | Rewrite (extend auth-layout) |
| `templates/public/reset-password-complete.twig` | Rewrite (extend auth-layout, message-only) |
| `templates/public/verification-result.twig` | Rewrite (extend auth-layout, conditional) |

No controller changes required. All existing template variables are preserved.

## Template Variable Contract

Each controller passes these variables. The new templates must consume all of them.

| Template | Variables |
|---|---|
| `login.twig` | `csrf_token`, `email`, `redirect` (hidden field, conditionally present), `error` |
| `signup.twig` | `csrf_token`, `email`, `name`, `error` |
| `forgot-password.twig` | `csrf_token`, `email`, `error` |
| `check-email.twig` | `email` |
| `reset-password.twig` | `csrf_token`, `token` (hidden field), `error` |
| `reset-password-complete.twig` | `status` (`complete` or `invalid`) |
| `verification-result.twig` | `status`, `tenant` (optional), `workspace` (optional), `workspace_sidecar_state` (optional), `account` (optional) |

## Testing

- All existing `PublicSessionControllerTest`, `PublicAccountControllerTest`, `PublicSessionCsrfTest`, and `PublicAccountLifecycleSmokeTest` tests must continue to pass
- Test assertions that match old template strings (e.g., "Log in to Claudriel", "Create Your Claudriel Account") must be updated to match the new copy
- Add template assertions where tests render with Twig: verify cross-link hrefs, autocomplete attributes, and shared layout markers are present
- Verify 768px responsive breakpoint collapses to single column

## Non-Goals

- No changes to auth controller logic or routing
- No new JavaScript
- No changes to the authenticated app shell templates
- No changes to the homepage template
