# Public Auth Shell Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace bare/inconsistent public auth templates with a shared split-layout Twig wrapper matching the marketing homepage design system.

**Architecture:** New `auth-layout.twig` extends `base.html.twig` with a two-column split grid (brand panel + form card). All 7 auth templates rewritten to extend it. No controller changes.

**Tech Stack:** Twig templating, CSS (no JS), PHPUnit

**Spec:** `docs/superpowers/specs/2026-03-15-public-auth-shell-design.md`

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `templates/public/auth-layout.twig` | Create | Shared split-grid layout with brand panel and form card blocks |
| `templates/public/login.twig` | Rewrite | Login form extending auth-layout |
| `templates/public/signup.twig` | Rewrite | Signup form extending auth-layout |
| `templates/public/forgot-password.twig` | Rewrite | Forgot password form extending auth-layout |
| `templates/public/check-email.twig` | Rewrite | Message-only page extending auth-layout |
| `templates/public/reset-password.twig` | Rewrite | Reset password form extending auth-layout |
| `templates/public/reset-password-complete.twig` | Rewrite | Conditional message page extending auth-layout |
| `templates/public/verification-result.twig` | Rewrite | Conditional message page extending auth-layout |
| `src/Support/PublicAccountDeployValidationScript.php` | Modify | Update grep markers to match new template copy |
| `tests/Unit/Controller/PublicSessionControllerTest.php` | Modify | Update assertion strings |
| `tests/Unit/Controller/PublicAccountControllerTest.php` | Modify | Update assertion strings |
| `tests/Unit/Support/PublicAccountDeployValidationScriptTest.php` | Modify | Update assertion strings |

---

## Chunk 1: Shared Layout and Login

### Task 1: Create auth-layout.twig

**Files:**
- Create: `templates/public/auth-layout.twig`

- [ ] **Step 1: Create the shared layout template**

```twig
{% extends "base.html.twig" %}

{% block title %}{{ block('auth_page_title') }} | Claudriel{% endblock %}

{% block styles %}
    .site-nav {
        background: transparent;
        border-bottom: 0;
    }
    body {
        min-height: 100vh;
        background:
            radial-gradient(circle at 20% 30%, rgba(107, 155, 255, 0.18), transparent 40%),
            radial-gradient(circle at 80% 70%, rgba(45, 212, 191, 0.12), transparent 35%),
            linear-gradient(180deg, #0b0d12 0%, #111521 52%, #0f1117 100%);
    }
    .auth-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        min-height: calc(100vh - 4rem);
        max-width: 1180px;
        margin: 0 auto;
        padding: 0 1.4rem;
        gap: 2rem;
        align-items: center;
    }
    .auth-brand {
        padding: 3rem 0;
    }
    .auth-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        font-size: 0.78rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--accent-teal);
        margin-bottom: 1rem;
    }
    .auth-eyebrow::before {
        content: "";
        width: 2.4rem;
        height: 1px;
        background: linear-gradient(90deg, var(--accent-teal), rgba(45, 212, 191, 0));
    }
    .auth-brand h1 {
        font-family: var(--font-display);
        font-size: clamp(2.2rem, 5vw, 3.6rem);
        line-height: 1;
        letter-spacing: -0.035em;
        margin-bottom: 0.75rem;
    }
    .auth-brand p {
        font-size: 1.05rem;
        color: #ccd0de;
        max-width: 28rem;
    }
    .auth-card {
        width: 100%;
        max-width: 420px;
        margin: 0 auto;
        padding: 2rem;
        border-radius: 1.2rem;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.06);
    }
    .auth-card h2 {
        font-family: var(--font-display);
        font-size: 1.4rem;
        margin-bottom: 1.4rem;
    }
    .auth-field {
        display: grid;
        gap: 0.35rem;
        margin-bottom: 1rem;
    }
    .auth-field label {
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--text-secondary);
    }
    .auth-field input {
        width: 100%;
        padding: 0.75rem 0.9rem;
        border-radius: 0.65rem;
        border: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(255, 255, 255, 0.06);
        color: var(--text-primary);
        font-family: var(--font-body);
        font-size: 0.95rem;
        transition: border-color 0.15s ease;
    }
    .auth-field input:focus {
        outline: none;
        border-color: var(--accent-teal);
        box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.12);
    }
    .auth-forgot {
        display: block;
        margin: -0.4rem 0 1rem;
        font-size: 0.8rem;
        color: var(--text-muted);
        text-align: right;
    }
    .auth-forgot a {
        color: var(--accent-teal);
        text-decoration: none;
    }
    .auth-forgot a:hover {
        text-decoration: underline;
    }
    .auth-submit {
        display: block;
        width: 100%;
        padding: 0.85rem;
        border: 0;
        border-radius: 999px;
        font-weight: 600;
        font-family: var(--font-body);
        font-size: 0.95rem;
        cursor: pointer;
        background: linear-gradient(135deg, #f0b040 0%, #d46337 100%);
        color: #15120e;
        box-shadow: 0 12px 30px rgba(212, 99, 55, 0.2);
        transition: transform 0.18s ease;
    }
    .auth-submit:hover {
        transform: translateY(-1px);
    }
    .auth-error {
        padding: 0.75rem 0.9rem;
        margin-bottom: 1rem;
        border-radius: 0.65rem;
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.25);
        color: #fca5a5;
        font-size: 0.85rem;
    }
    .auth-links {
        margin-top: 1.2rem;
        text-align: center;
        font-size: 0.85rem;
        color: var(--text-secondary);
    }
    .auth-links a {
        color: var(--accent-teal);
        text-decoration: none;
    }
    .auth-links a:hover {
        text-decoration: underline;
    }
    .auth-message {
        text-align: center;
        padding: 1rem 0;
    }
    .auth-message p {
        color: var(--text-secondary);
        margin-top: 0.5rem;
    }
    @media (max-width: 768px) {
        .auth-split {
            grid-template-columns: 1fr;
            padding-top: 1rem;
        }
        .auth-brand {
            padding: 1rem 0;
            text-align: center;
        }
        .auth-brand p {
            margin: 0 auto;
        }
    }
{% endblock %}

{% block content %}
    <main class="auth-split">
        <div class="auth-brand">
            <div class="auth-eyebrow">{% block auth_eyebrow %}{% endblock %}</div>
            <h1>{% block auth_headline %}{% endblock %}</h1>
            <p>{% block auth_subtext %}{% endblock %}</p>
        </div>
        <div>
            <div class="auth-card">
                {% block auth_form %}{% endblock %}
            </div>
            <div class="auth-links">
                {% block auth_links %}{% endblock %}
            </div>
        </div>
    </main>
{% endblock %}

{% block auth_page_title %}Claudriel{% endblock %}
```

- [ ] **Step 2: Verify the layout renders without errors**

Run: `php -r "require 'vendor/autoload.php'; \$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader('templates')); echo 'OK';"`
Expected: OK (no Twig parse errors)

- [ ] **Step 3: Commit**

```bash
git add templates/public/auth-layout.twig
git commit -m "feat(#146): add shared auth-layout.twig split-grid wrapper"
```

---

### Task 2: Rewrite login.twig

**Files:**
- Rewrite: `templates/public/login.twig`
- Modify: `tests/Unit/Controller/PublicSessionControllerTest.php:42`

- [ ] **Step 1: Rewrite login.twig to extend auth-layout**

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}Log In{% endblock %}

{% block auth_eyebrow %}Welcome back{% endblock %}
{% block auth_headline %}Pick up where you left off.{% endblock %}
{% block auth_subtext %}Your schedule, commitments, and workspace are waiting.{% endblock %}

{% block auth_form %}
    <h2>Log in</h2>

    {% if error is defined and error %}
        <div class="auth-error">{{ error }}</div>
    {% endif %}

    <form method="post" action="/login">
        <input type="hidden" name="_csrf_token" value="{{ csrf_token }}">
        {% if redirect is defined and redirect %}
            <input type="hidden" name="redirect" value="{{ redirect }}">
        {% endif %}

        <div class="auth-field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ email|default('') }}" autocomplete="email" required>
        </div>

        <div class="auth-field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
        </div>

        <div class="auth-forgot">
            <a href="/forgot-password">Forgot your password?</a>
        </div>

        <button type="submit" class="auth-submit">Log in</button>
    </form>
{% endblock %}

{% block auth_links %}
    Don't have an account? <a href="/signup">Sign up</a>
{% endblock %}
```

- [ ] **Step 2: Update test assertion in PublicSessionControllerTest**

In `tests/Unit/Controller/PublicSessionControllerTest.php` line 42, change:
```php
self::assertStringContainsString('Log in to Claudriel', $response->content);
```
to:
```php
self::assertStringContainsString('Pick up where you left off.', $response->content);
```

- [ ] **Step 3: Run tests**

Run: `php vendor/bin/phpunit --filter PublicSessionControllerTest`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add templates/public/login.twig tests/Unit/Controller/PublicSessionControllerTest.php
git commit -m "feat(#146): rewrite login.twig on shared auth layout"
```

---

### Task 3: Rewrite signup.twig

**Files:**
- Rewrite: `templates/public/signup.twig`
- Modify: `tests/Unit/Controller/PublicAccountControllerTest.php:36`

- [ ] **Step 1: Rewrite signup.twig to extend auth-layout**

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}Sign Up{% endblock %}

{% block auth_eyebrow %}Get started{% endblock %}
{% block auth_headline %}Run your day before it runs you.{% endblock %}
{% block auth_subtext %}Create your account to start.{% endblock %}

{% block auth_form %}
    <h2>Create your account</h2>

    {% if error is defined and error %}
        <div class="auth-error">{{ error }}</div>
    {% endif %}

    <form method="post" action="/signup">
        <input type="hidden" name="_csrf_token" value="{{ csrf_token }}">

        <div class="auth-field">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="{{ name|default('') }}" autocomplete="name" required>
        </div>

        <div class="auth-field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ email|default('') }}" autocomplete="email" required>
        </div>

        <div class="auth-field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>
        </div>

        <button type="submit" class="auth-submit">Create account</button>
    </form>
{% endblock %}

{% block auth_links %}
    Already have an account? <a href="/login">Log in</a>
{% endblock %}
```

- [ ] **Step 2: Update test assertion in PublicAccountControllerTest**

In `tests/Unit/Controller/PublicAccountControllerTest.php` line 36, change:
```php
self::assertStringContainsString('Create Your Claudriel Account', $response->content);
```
to:
```php
self::assertStringContainsString('Create your account', $response->content);
```

- [ ] **Step 3: Run tests**

Run: `php vendor/bin/phpunit --filter PublicAccountControllerTest`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add templates/public/signup.twig tests/Unit/Controller/PublicAccountControllerTest.php
git commit -m "feat(#146): rewrite signup.twig on shared auth layout"
```

---

## Chunk 2: Password Reset Flow Templates

### Task 4: Rewrite forgot-password.twig

**Files:**
- Rewrite: `templates/public/forgot-password.twig`

- [ ] **Step 1: Rewrite forgot-password.twig**

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}Forgot Password{% endblock %}

{% block auth_eyebrow %}Account recovery{% endblock %}
{% block auth_headline %}Reset your password.{% endblock %}
{% block auth_subtext %}We'll send a link to your email.{% endblock %}

{% block auth_form %}
    <h2>Forgot password</h2>

    {% if error is defined and error %}
        <div class="auth-error">{{ error }}</div>
    {% endif %}

    <form method="post" action="/forgot-password">
        <input type="hidden" name="_csrf_token" value="{{ csrf_token }}">

        <div class="auth-field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ email|default('') }}" autocomplete="email" required>
        </div>

        <button type="submit" class="auth-submit">Send reset link</button>
    </form>
{% endblock %}

{% block auth_links %}
    <a href="/login">Back to log in</a>
{% endblock %}
```

- [ ] **Step 2: Run tests**

Run: `php vendor/bin/phpunit --filter PublicPasswordResetControllerTest`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add templates/public/forgot-password.twig
git commit -m "feat(#146): rewrite forgot-password.twig on shared auth layout"
```

---

### Task 5: Rewrite check-email.twig

**Files:**
- Rewrite: `templates/public/check-email.twig`

- [ ] **Step 1: Rewrite check-email.twig**

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}Check Your Email{% endblock %}

{% block auth_eyebrow %}Check your inbox{% endblock %}
{% block auth_headline %}We sent you a link.{% endblock %}
{% block auth_subtext %}Follow the link in your email to continue.{% endblock %}

{% block auth_form %}
    <div class="auth-message">
        <h2>Check {{ email|default('your email') }}</h2>
        <p>If an account exists for that address, you'll receive an email shortly.</p>
    </div>
{% endblock %}

{% block auth_links %}
    <a href="/login">Back to log in</a>
{% endblock %}
```

- [ ] **Step 2: Run tests**

Run: `php vendor/bin/phpunit --filter 'PublicPasswordResetControllerTest|PublicAccountControllerTest'`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add templates/public/check-email.twig
git commit -m "feat(#146): rewrite check-email.twig on shared auth layout"
```

---

### Task 6: Rewrite reset-password.twig

**Files:**
- Rewrite: `templates/public/reset-password.twig`

- [ ] **Step 1: Rewrite reset-password.twig**

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}Reset Password{% endblock %}

{% block auth_eyebrow %}Almost there{% endblock %}
{% block auth_headline %}Choose a new password.{% endblock %}
{% block auth_subtext %}Enter your new password below.{% endblock %}

{% block auth_form %}
    <h2>New password</h2>

    {% if error is defined and error %}
        <div class="auth-error">{{ error }}</div>
    {% endif %}

    <form method="post" action="/reset-password/{{ token }}">
        <input type="hidden" name="_csrf_token" value="{{ csrf_token }}">
        <input type="hidden" name="token" value="{{ token }}">

        <div class="auth-field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>
        </div>

        <button type="submit" class="auth-submit">Reset password</button>
    </form>
{% endblock %}

{% block auth_links %}
    <a href="/login">Back to log in</a>
{% endblock %}
```

- [ ] **Step 2: Run tests**

Run: `php vendor/bin/phpunit --filter PublicPasswordResetControllerTest`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add templates/public/reset-password.twig
git commit -m "feat(#146): rewrite reset-password.twig on shared auth layout"
```

---

### Task 7: Rewrite reset-password-complete.twig

**Files:**
- Rewrite: `templates/public/reset-password-complete.twig`

- [ ] **Step 1: Rewrite reset-password-complete.twig with conditional states**

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}Password Reset{% endblock %}

{% if status == 'complete' %}
    {% block auth_eyebrow %}Done{% endblock %}
    {% block auth_headline %}Password updated.{% endblock %}
    {% block auth_subtext %}You can now log in with your new password.{% endblock %}
{% else %}
    {% block auth_eyebrow %}Something went wrong{% endblock %}
    {% block auth_headline %}That reset link is invalid or expired.{% endblock %}
    {% block auth_subtext %}Request a new one to try again.{% endblock %}
{% endif %}

{% block auth_form %}
    <div class="auth-message">
        {% if status == 'complete' %}
            <h2>All set</h2>
            <p>Your password has been updated successfully.</p>
        {% else %}
            <h2>Reset failed</h2>
            <p>The link may have expired or already been used.</p>
        {% endif %}
    </div>
{% endblock %}

{% block auth_links %}
    {% if status == 'complete' %}
        <a href="/login">Log in</a>
    {% else %}
        <a href="/forgot-password">Request a new link</a>
    {% endif %}
{% endblock %}
```

Note: Twig does not allow conditional block overrides with `{% if %}` around `{% block %}`. The correct approach is to use block content with conditionals inside:

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}Password Reset{% endblock %}

{% block auth_eyebrow %}{% if status == 'complete' %}Done{% else %}Something went wrong{% endif %}{% endblock %}
{% block auth_headline %}{% if status == 'complete' %}Password updated.{% else %}That reset link is invalid or expired.{% endif %}{% endblock %}
{% block auth_subtext %}{% if status == 'complete' %}You can now log in with your new password.{% else %}Request a new one to try again.{% endif %}{% endblock %}

{% block auth_form %}
    <div class="auth-message">
        {% if status == 'complete' %}
            <h2>All set</h2>
            <p>Your password has been updated successfully.</p>
        {% else %}
            <h2>Reset failed</h2>
            <p>The link may have expired or already been used.</p>
        {% endif %}
    </div>
{% endblock %}

{% block auth_links %}
    {% if status == 'complete' %}
        <a href="/login">Log in</a>
    {% else %}
        <a href="/forgot-password">Request a new link</a>
    {% endif %}
{% endblock %}
```

Use this second version (conditionals inside blocks).

- [ ] **Step 2: Run tests**

Run: `php vendor/bin/phpunit --filter PublicPasswordResetControllerTest`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add templates/public/reset-password-complete.twig
git commit -m "feat(#146): rewrite reset-password-complete.twig on shared auth layout"
```

---

## Chunk 3: Verification, Deploy Script, and Final Validation

### Task 8: Rewrite verification-result.twig

**Files:**
- Rewrite: `templates/public/verification-result.twig`

- [ ] **Step 1: Rewrite verification-result.twig with conditional states**

```twig
{% extends "public/auth-layout.twig" %}

{% block auth_page_title %}{% if status == 'verified' %}Email Verified{% elseif status == 'invalid' %}Verification Failed{% else %}Verification Pending{% endif %}{% endblock %}

{% block auth_eyebrow %}{% if status == 'verified' %}You're verified{% elseif status == 'invalid' %}Something went wrong{% else %}Almost there{% endif %}{% endblock %}
{% block auth_headline %}{% if status == 'verified' %}Account ready.{% elseif status == 'invalid' %}That verification link is invalid or expired.{% else %}Check your email for the verification link.{% endif %}{% endblock %}
{% block auth_subtext %}{% if status == 'verified' %}Your tenant and workspace have been set up.{% elseif status == 'invalid' %}Try signing up again.{% else %}We sent it when you signed up.{% endif %}{% endblock %}

{% block auth_form %}
    <div class="auth-message">
        {% if status == 'verified' %}
            <h2>Welcome aboard</h2>
            <p>Your account is verified and ready to use.</p>
        {% elseif status == 'invalid' %}
            <h2>Verification failed</h2>
            <p>The link may have expired or already been used.</p>
        {% else %}
            <h2>Waiting for verification</h2>
            <p>Check your inbox for the verification email we sent.</p>
        {% endif %}
    </div>
{% endblock %}

{% block auth_links %}
    {% if status == 'verified' %}
        <a href="/login">Log in</a>
    {% elseif status == 'invalid' %}
        <a href="/signup">Sign up again</a>
    {% else %}
        <a href="/login">Log in</a>
    {% endif %}
{% endblock %}
```

- [ ] **Step 2: Run tests**

Run: `php vendor/bin/phpunit --filter PublicAccountControllerTest`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add templates/public/verification-result.twig
git commit -m "feat(#146): rewrite verification-result.twig on shared auth layout"
```

---

### Task 9: Update deploy validation script and its tests

**Files:**
- Modify: `src/Support/PublicAccountDeployValidationScript.php:43,64`
- Modify: `tests/Unit/Support/PublicAccountDeployValidationScriptTest.php:21-22`

- [ ] **Step 1: Update grep markers in deploy validation script**

In `src/Support/PublicAccountDeployValidationScript.php`:

Line 43, change:
```
grep -q 'Create Your Claudriel Account' "$signup_form_file"
```
to:
```
grep -q 'Create your account' "$signup_form_file"
```

Line 64, change:
```
grep -q 'Log in to Claudriel' "$login_form_file"
```
to:
```
grep -q 'Pick up where you left off.' "$login_form_file"
```

- [ ] **Step 2: Update test assertions**

In `tests/Unit/Support/PublicAccountDeployValidationScriptTest.php`:

Line 21, change:
```php
self::assertStringContainsString('Create Your Claudriel Account', $script);
```
to:
```php
self::assertStringContainsString('Create your account', $script);
```

Line 22, change:
```php
self::assertStringContainsString('Log in to Claudriel', $script);
```
to:
```php
self::assertStringContainsString('Pick up where you left off.', $script);
```

- [ ] **Step 3: Run tests**

Run: `php vendor/bin/phpunit --filter PublicAccountDeployValidationScriptTest`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add src/Support/PublicAccountDeployValidationScript.php tests/Unit/Support/PublicAccountDeployValidationScriptTest.php
git commit -m "feat(#147): update deploy validation markers for new auth shell copy"
```

---

### Task 10: Full test suite validation and push

- [ ] **Step 1: Run full test suite**

Run: `php vendor/bin/phpunit`
Expected: All 334+ tests pass, 0 failures

- [ ] **Step 2: Run pint to verify code style**

Run: `vendor/bin/pint --test`
Expected: Pass

- [ ] **Step 3: Push**

Run: `git push`
Expected: Pre-push hooks pass, push succeeds

- [ ] **Step 4: Close issues**

```bash
gh issue close 146 --comment "Shared auth-layout.twig wrapper implemented. All 7 auth templates rewritten with split-grid layout matching homepage design system."
gh issue close 147 --comment "Cross-links, autocomplete attributes, contextual copy, and deploy validation markers updated across all public auth pages."
```
