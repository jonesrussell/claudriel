# Google Sign-In Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow users to sign up and log in with their Google account, creating password-less accounts that skip email verification.

**Architecture:** Add `signin()` and `signinCallback()` methods to the existing `GoogleOAuthController` with identity-only scopes (`openid email profile`). New routes `/auth/google/signin` and `/auth/google/signin/callback` separate sign-in from service-connection OAuth. `PublicAccountSignupService` gets a `createFromGoogle()` method. Login form rejects password-less accounts with a helpful message.

**Tech Stack:** PHP 8.4, Waaseyaa framework, Google OAuth 2.0, Twig templates

---

### Task 1: Add `createFromGoogle()` to PublicAccountSignupService

**Files:**
- Modify: `src/Service/PublicAccountSignupService.php`
- Test: `tests/Unit/Service/PublicAccountSignupServiceTest.php`

- [ ] **Step 1: Write the failing test for createFromGoogle**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use Claudriel\Entity\Account;
use Claudriel\Service\PublicAccountSignupService;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\InMemoryStorageDriver;
use Waaseyaa\Entity\EntityType;

final class PublicAccountSignupServiceGoogleTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private PublicAccountSignupService $service;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager();

        $accountType = new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        $this->entityTypeManager->addEntityType($accountType);
        $this->entityTypeManager->addStorage('account', new InMemoryStorageDriver($accountType));

        $this->service = new PublicAccountSignupService($this->entityTypeManager);
    }

    public function testCreateFromGoogleCreatesActiveAccount(): void
    {
        $account = $this->service->createFromGoogle('ada@example.com', 'Ada Lovelace');

        self::assertInstanceOf(Account::class, $account);
        self::assertSame('ada@example.com', $account->get('email'));
        self::assertSame('Ada Lovelace', $account->get('name'));
        self::assertNull($account->get('password_hash'));
        self::assertSame('active', $account->get('status'));
        self::assertNotNull($account->get('email_verified_at'));
    }

    public function testCreateFromGoogleReturnsExistingAccountIfEmailMatches(): void
    {
        $first = $this->service->createFromGoogle('ada@example.com', 'Ada Lovelace');
        $second = $this->service->createFromGoogle('ada@example.com', 'Ada L');

        self::assertSame($first->get('uuid'), $second->get('uuid'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/PublicAccountSignupServiceGoogleTest.php -v`
Expected: FAIL with "Call to undefined method createFromGoogle"

- [ ] **Step 3: Implement createFromGoogle**

Add this method to `src/Service/PublicAccountSignupService.php` after the existing `signup()` method:

```php
public function createFromGoogle(string $email, string $name): Account
{
    $email = strtolower(trim($email));
    $name = trim($name);

    $existing = $this->findAccountByEmail($email);
    if ($existing instanceof Account) {
        if ($existing->get('status') === 'pending_verification') {
            $existing->set('status', 'active');
            $existing->set('email_verified_at', (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM));
            $this->entityTypeManager->getStorage('account')->save($existing);
        }

        return $existing;
    }

    $account = new Account([
        'name' => $name,
        'email' => $email,
        'password_hash' => null,
        'status' => 'active',
        'email_verified_at' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
        'roles' => [],
        'permissions' => [],
    ]);
    $this->entityTypeManager->getStorage('account')->save($account);

    return $account;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/PublicAccountSignupServiceGoogleTest.php -v`
Expected: PASS (2 tests, all assertions green)

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All 691+ tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Service/PublicAccountSignupService.php tests/Unit/Service/PublicAccountSignupServiceGoogleTest.php
git commit -m "feat(#TBD): add createFromGoogle to PublicAccountSignupService"
```

---

### Task 2: Add sign-in OAuth methods to GoogleOAuthController

**Files:**
- Modify: `src/Controller/GoogleOAuthController.php`
- Test: `tests/Unit/Controller/GoogleOAuthSigninTest.php`

- [ ] **Step 1: Write the failing test for signin redirect**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use Claudriel\Controller\GoogleOAuthController;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;

final class GoogleOAuthSigninTest extends TestCase
{
    private GoogleOAuthController $controller;

    protected function setUp(): void
    {
        $_ENV['GOOGLE_CLIENT_ID'] = 'test-client-id';
        $_ENV['GOOGLE_CLIENT_SECRET'] = 'test-secret';
        $_ENV['GOOGLE_REDIRECT_URI'] = 'https://claudriel.ai/auth/google/callback';
        $_ENV['GOOGLE_SIGNIN_REDIRECT_URI'] = 'https://claudriel.ai/auth/google/signin/callback';

        $this->controller = new GoogleOAuthController(new EntityTypeManager());
    }

    protected function tearDown(): void
    {
        unset($_ENV['GOOGLE_CLIENT_ID'], $_ENV['GOOGLE_CLIENT_SECRET'], $_ENV['GOOGLE_REDIRECT_URI'], $_ENV['GOOGLE_SIGNIN_REDIRECT_URI']);
    }

    public function testSigninRedirectsToGoogleWithIdentityScopes(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $response = $this->controller->signin();

        self::assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        self::assertStringContainsString('accounts.google.com', $location);
        self::assertStringContainsString('openid', $location);
        self::assertStringContainsString('userinfo.email', $location);
        self::assertStringContainsString('userinfo.profile', $location);
        self::assertStringNotContainsString('gmail', $location);
        self::assertStringNotContainsString('calendar', $location);
        self::assertStringContainsString('signin%2Fcallback', $location);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Controller/GoogleOAuthSigninTest.php -v`
Expected: FAIL with "Call to undefined method signin"

- [ ] **Step 3: Implement signin() and signinCallback()**

Add these constants and methods to `src/Controller/GoogleOAuthController.php`:

Add a new constant after the existing `SCOPES`:

```php
private const SIGNIN_SCOPES = [
    'openid',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile',
];
```

Add a new property after `$redirectUri`:

```php
private readonly string $signinRedirectUri;
```

In the constructor, after the existing `$this->redirectUri` line, add:

```php
$this->signinRedirectUri = $_ENV['GOOGLE_SIGNIN_REDIRECT_URI'] ?? getenv('GOOGLE_SIGNIN_REDIRECT_URI') ?: '';
```

Add the `signin()` method:

```php
public function signin(
    array $params = [],
    array $query = [],
    ?AccountInterface $account = null,
    ?Request $httpRequest = null,
): RedirectResponse {
    $state = bin2hex(random_bytes(32));
    $_SESSION['google_oauth_state'] = $state;
    $_SESSION['google_oauth_flow'] = 'signin';

    $authUrl = self::AUTH_ENDPOINT.'?'.http_build_query([
        'client_id' => $this->clientId,
        'redirect_uri' => $this->signinRedirectUri,
        'response_type' => 'code',
        'scope' => implode(' ', self::SIGNIN_SCOPES),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state,
    ]);

    return new RedirectResponse($authUrl, 302);
}
```

Add the `signinCallback()` method:

```php
public function signinCallback(
    array $params = [],
    array $query = [],
    ?AccountInterface $account = null,
    ?Request $httpRequest = null,
): RedirectResponse {
    if (isset($query['error'])) {
        $_SESSION['flash_error'] = 'Google sign-in denied: '.$query['error'];

        return new RedirectResponse('/login', 302);
    }

    $expectedState = $_SESSION['google_oauth_state'] ?? null;
    $expectedFlow = $_SESSION['google_oauth_flow'] ?? null;
    unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_flow']);

    if ($expectedState === null || $expectedFlow !== 'signin' || !hash_equals($expectedState, $query['state'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';

        return new RedirectResponse('/login', 302);
    }

    $tokenData = $this->exchangeCodeForTokens($query['code'] ?? '', $this->signinRedirectUri);

    if ($tokenData === null) {
        $_SESSION['flash_error'] = 'Failed to exchange authorization code.';

        return new RedirectResponse('/login', 302);
    }

    $userInfo = $this->fetchUserInfo($tokenData['access_token']);
    $email = $userInfo['email'] ?? null;
    $name = $userInfo['name'] ?? '';
    $emailVerified = $userInfo['verified_email'] ?? false;

    if ($email === null || !$emailVerified) {
        $_SESSION['flash_error'] = 'Google account email is not verified.';

        return new RedirectResponse('/login', 302);
    }

    $signupService = new \Claudriel\Service\PublicAccountSignupService($this->entityTypeManager);
    $accountEntity = $signupService->createFromGoogle($email, $name);

    if (session_status() !== \PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['claudriel_account_uuid'] = $accountEntity->get('uuid');
    session_regenerate_id(true);
    \Waaseyaa\User\Middleware\CsrfMiddleware::regenerate();

    $this->upsertIntegration(
        new \Claudriel\Access\AuthenticatedAccount(
            id: (int) $accountEntity->get('aid'),
            uuid: (string) $accountEntity->get('uuid'),
            email: (string) $accountEntity->get('email'),
            roles: [],
        ),
        $tokenData,
        $email,
    );

    return new RedirectResponse('/app', 302);
}
```

- [ ] **Step 4: Update exchangeCodeForTokens to accept redirect_uri parameter**

Change the existing `exchangeCodeForTokens` signature to accept an optional redirect URI:

```php
private function exchangeCodeForTokens(string $code, ?string $overrideRedirectUri = null): ?array
```

And update the payload line inside it:

```php
'redirect_uri' => $overrideRedirectUri ?? $this->redirectUri,
```

Also update the existing `callback()` method's call to pass null explicitly (no behavior change):

```php
$tokenData = $this->exchangeCodeForTokens($query['code'] ?? '');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Controller/GoogleOAuthSigninTest.php -v`
Expected: PASS

- [ ] **Step 6: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass (existing callback tests still green)

- [ ] **Step 7: Commit**

```bash
git add src/Controller/GoogleOAuthController.php tests/Unit/Controller/GoogleOAuthSigninTest.php
git commit -m "feat(#TBD): add signin and signinCallback to GoogleOAuthController"
```

---

### Task 3: Register sign-in routes

**Files:**
- Modify: `src/Provider/AccountServiceProvider.php`

- [ ] **Step 1: Add the two new routes**

In `src/Provider/AccountServiceProvider.php`, add after the existing Google OAuth callback route block (after line 275):

```php
// Google OAuth Sign-In (identity only, no service scopes)
$router->addRoute(
    'claudriel.auth.google.signin',
    RouteBuilder::create('/auth/google/signin')
        ->controller(GoogleOAuthController::class.'::signin')
        ->allowAll()
        ->methods('GET')
        ->build(),
);

$googleSigninCallbackRoute = RouteBuilder::create('/auth/google/signin/callback')
    ->controller(GoogleOAuthController::class.'::signinCallback')
    ->allowAll()
    ->methods('GET')
    ->build();
$googleSigninCallbackRoute->setOption('_csrf', false);
$router->addRoute('claudriel.auth.google.signin.callback', $googleSigninCallbackRoute);
```

- [ ] **Step 2: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add src/Provider/AccountServiceProvider.php
git commit -m "feat(#TBD): register Google sign-in routes"
```

---

### Task 4: Add GOOGLE_SIGNIN_REDIRECT_URI to environment

**Files:**
- Modify: `.env.example` (if it exists, otherwise document in commit)

- [ ] **Step 1: Check for .env.example**

Run: `ls -la .env*`

- [ ] **Step 2: Add the new env var**

Add `GOOGLE_SIGNIN_REDIRECT_URI` to `.env.example` (or `.env` locally):

```
GOOGLE_SIGNIN_REDIRECT_URI=https://claudriel.ai/auth/google/signin/callback
```

For local dev:
```
GOOGLE_SIGNIN_REDIRECT_URI=http://localhost:8081/auth/google/signin/callback
```

- [ ] **Step 3: Add the callback URI in Google Cloud Console**

The new callback URI `https://claudriel.ai/auth/google/signin/callback` must be added to the OAuth client's "Authorized redirect URIs" in Google Cloud Console. This is a manual step.

- [ ] **Step 4: Add to Ansible vault for production**

Add `vault_claudriel_prod_google_signin_redirect_uri` to the vault, or reuse the existing redirect URI pattern. The Ansible deploy template needs to include this env var.

- [ ] **Step 5: Commit**

```bash
git add .env.example
git commit -m "feat(#TBD): add GOOGLE_SIGNIN_REDIRECT_URI env var"
```

---

### Task 5: Reject password-less accounts on email/password login

**Files:**
- Modify: `src/Controller/PublicSessionController.php`
- Test: `tests/Unit/Controller/PublicSessionControllerTest.php` (existing, add test)

- [ ] **Step 1: Write failing test**

Add to the existing test file (or create if it doesn't exist):

```php
public function testLoginRejectsPasswordlessAccountWithGoogleMessage(): void
{
    // Create a password-less account (Google sign-in user)
    $account = new Account([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password_hash' => null,
        'status' => 'active',
        'email_verified_at' => '2026-03-27T00:00:00+00:00',
        'roles' => [],
        'permissions' => [],
    ]);
    $this->entityTypeManager->getStorage('account')->save($account);

    $request = Request::create('/login', 'POST', [
        'email' => 'ada@example.com',
        'password' => 'anything',
    ]);

    $response = $this->controller->login([], [], null, $request);

    self::assertSame(401, $response->getStatusCode());
    // The response should mention Google sign-in
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Controller/PublicSessionControllerTest.php --filter=testLoginRejectsPasswordless -v`
Expected: FAIL (currently returns generic "Invalid credentials")

- [ ] **Step 3: Update login method**

In `src/Controller/PublicSessionController.php`, modify the `login()` method. Replace the credential check block (lines 59-67):

```php
$resolvedAccount = $this->findVerifiedAccountByEmail($email);

if (! $resolvedAccount instanceof Account) {
    return $this->render('public/login.twig', [
        'csrf_token' => CsrfMiddleware::token(),
        'email' => $email,
        'redirect' => $redirect,
        'error' => 'Invalid credentials.',
    ], 401);
}

$passwordHash = (string) $resolvedAccount->get('password_hash');

if ($passwordHash === '') {
    return $this->render('public/login.twig', [
        'csrf_token' => CsrfMiddleware::token(),
        'email' => $email,
        'redirect' => $redirect,
        'error' => 'This account uses Google sign-in. Use the "Sign in with Google" button below.',
        'show_google_signin' => true,
    ], 401);
}

if (! password_verify($password, $passwordHash)) {
    return $this->render('public/login.twig', [
        'csrf_token' => CsrfMiddleware::token(),
        'email' => $email,
        'redirect' => $redirect,
        'error' => 'Invalid credentials.',
    ], 401);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Controller/PublicSessionControllerTest.php --filter=testLoginRejectsPasswordless -v`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Controller/PublicSessionController.php tests/Unit/Controller/PublicSessionControllerTest.php
git commit -m "feat(#TBD): reject password-less accounts on email/password login"
```

---

### Task 6: Add "Sign in with Google" button to login and signup templates

**Files:**
- Modify: `templates/public/login.twig`
- Modify: `templates/public/signup.twig`

- [ ] **Step 1: Add Google button to login template**

In `templates/public/login.twig`, add after the `<h2>Log in</h2>` line (line 10) and before the verified/error blocks:

```twig
    <a href="/auth/google/signin" class="auth-google-btn">
        <svg viewBox="0 0 24 24" width="18" height="18" style="margin-right: 0.5rem;">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
        Sign in with Google
    </a>

    <div class="auth-divider"><span>or</span></div>
```

- [ ] **Step 2: Add Google button to signup template**

In `templates/public/signup.twig`, add after the error block (line 169) and before the `<form>` tag:

```twig
        <a href="/auth/google/signin" class="signup-google-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" style="margin-right: 0.5rem;">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Sign up with Google
        </a>

        <div class="signup-divider"><span>or continue with email</span></div>
```

- [ ] **Step 3: Add CSS for the Google buttons**

In `templates/public/login.twig`, the button styling is inherited from `auth-layout.twig`. Add to the auth layout or inline in login.twig's styles. The login template extends `public/auth-layout.twig` which likely has a `{% block styles %}`. Add these styles:

For login (add to `auth-layout.twig` or as inline styles in login.twig):

```css
.auth-google-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 0.85rem 1rem;
    border-radius: 0.65rem;
    border: 1px solid var(--border-emphasis);
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-primary);
    font-family: var(--font-body);
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.15s, border-color 0.15s;
    margin-bottom: 0;
}
.auth-google-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: var(--accent-teal);
}
.auth-divider {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin: 1.2rem 0;
    color: var(--text-muted);
    font-size: 0.82rem;
}
.auth-divider::before,
.auth-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: var(--border);
}
```

For signup, add equivalent styles in the signup.twig `{% block styles %}`:

```css
.signup-google-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    max-width: 480px;
    margin: 0 auto 0;
    padding: 0.85rem 1rem;
    border-radius: 0.65rem;
    border: 1px solid var(--border-emphasis);
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-primary);
    font-family: var(--font-body);
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.15s, border-color 0.15s;
}
.signup-google-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: var(--accent-teal);
}
.signup-divider {
    display: flex;
    align-items: center;
    gap: 1rem;
    max-width: 480px;
    margin: 1.2rem auto;
    color: var(--text-muted);
    font-size: 0.82rem;
}
.signup-divider::before,
.signup-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: var(--border);
}
```

- [ ] **Step 4: Verify templates render**

Run: `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8081 -t public &`
Visit `http://localhost:8081/login` and `http://localhost:8081/signup` to verify the Google buttons render correctly.

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add templates/public/login.twig templates/public/signup.twig
git commit -m "feat(#TBD): add Sign in with Google buttons to login and signup forms"
```

---

### Task 7: Create GitHub issue and update env in Ansible

**Files:**
- None (GitHub + Ansible vault)

- [ ] **Step 1: Create the GitHub issue**

```bash
gh issue create --repo jonesrussell/claudriel \
  --title "feat: Google Sign-In (register and login with Google)" \
  --body "Allow users to sign up and log in with their Google account. Password-less accounts, skip email verification. See docs/superpowers/specs/2026-03-27-google-sign-in-design.md" \
  --milestone "v2.3 — Onboarding and Export"
```

- [ ] **Step 2: Update all commit messages with issue number**

Replace `#TBD` in previous commits with the actual issue number (or use `git rebase -i` if still local).

- [ ] **Step 3: Add callback URI to Google Cloud Console**

Manual step: In Google Cloud Console > APIs & Services > Credentials > OAuth client (Prod web client), add:
- `https://claudriel.ai/auth/google/signin/callback`

- [ ] **Step 4: Add env var to Ansible**

In northcloud-ansible, add `GOOGLE_SIGNIN_REDIRECT_URI` to the Claudriel app's env template, sourcing from vault or deriving from the app URL.

- [ ] **Step 5: Push and verify deploy**

```bash
git push
```

Wait for CI + deploy to pass. Verify at `https://claudriel.ai/login` that the Google button appears and the OAuth flow works.
