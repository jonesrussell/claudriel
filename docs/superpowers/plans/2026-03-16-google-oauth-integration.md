# Google OAuth Integration — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-account Google OAuth so each Claudriel account can connect their Google account (Gmail, Calendar, Drive) with token lifecycle management.

**Architecture:** OAuth redirect/callback flow via `GoogleOAuthController`, tokens stored on the `Integration` entity (one per account+provider), and a `GoogleTokenManager` that transparently handles token refresh. All files follow existing Waaseyaa patterns (raw curl, `$_SESSION`, `ContentEntityBase`, `EntityTypeManager`).

**Tech Stack:** PHP 8.4, Waaseyaa framework, Google OAuth 2.0 REST endpoints, SQLite (auto-schema), PHPUnit

**Spec:** `docs/superpowers/specs/2026-03-16-google-oauth-integration-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `src/Entity/Integration.php` | Modify | Add OAuth fields (account_id, provider, tokens, scopes, status, etc.) |
| `src/Support/GoogleTokenManagerInterface.php` | Create | Contract for token lifecycle |
| `src/Support/GoogleTokenManager.php` | Create | Token refresh, validation, storage updates |
| `src/Controller/GoogleOAuthController.php` | Create | OAuth redirect + callback routes |
| `src/Provider/ClaudrielServiceProvider.php` | Modify | Register Integration entity type, routes, and services |
| `tests/Unit/Entity/IntegrationTest.php` | Create | Integration entity field tests |
| `tests/Unit/Support/GoogleTokenManagerTest.php` | Create | Token manager logic tests |
| `tests/Unit/Controller/GoogleOAuthControllerTest.php` | Create | OAuth flow tests |

---

## Chunk 1: Integration Entity + Registration

### Task 1: Update Integration Entity with OAuth Fields

**Files:**
- Modify: `src/Entity/Integration.php`
- Create: `tests/Unit/Entity/IntegrationTest.php`

- [ ] **Step 1: Write failing test for Integration entity fields**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Integration;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    public function test_stores_google_oauth_fields(): void
    {
        $integration = new Integration([
            'account_id' => 'acc-123',
            'provider' => 'google',
            'access_token' => 'ya29.token',
            'refresh_token' => '1//refresh',
            'token_expires_at' => '2026-03-16T22:00:00Z',
            'scopes' => json_encode(['https://www.googleapis.com/auth/gmail.readonly']),
            'status' => 'active',
            'provider_email' => 'user@gmail.com',
            'metadata' => json_encode(['token_type' => 'Bearer']),
        ]);

        self::assertSame('acc-123', $integration->get('account_id'));
        self::assertSame('google', $integration->get('provider'));
        self::assertSame('ya29.token', $integration->get('access_token'));
        self::assertSame('1//refresh', $integration->get('refresh_token'));
        self::assertSame('active', $integration->get('status'));
        self::assertSame('user@gmail.com', $integration->get('provider_email'));
    }

    public function test_defaults_status_to_pending(): void
    {
        $integration = new Integration([
            'account_id' => 'acc-123',
            'provider' => 'google',
        ]);

        self::assertSame('pending', $integration->get('status'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Entity/IntegrationTest.php -v`
Expected: FAIL (status default not implemented)

- [ ] **Step 3: Update Integration entity with fields**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Integration extends ContentEntityBase
{
    protected string $entityTypeId = 'integration';

    protected array $entityKeys = [
        'id' => 'iid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        if (!isset($values['status'])) {
            $values['status'] = 'pending';
        }

        parent::__construct($values, 'integration', $this->entityKeys);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Entity/IntegrationTest.php -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Entity/Integration.php tests/Unit/Entity/IntegrationTest.php
git commit -m "feat: add OAuth fields to Integration entity"
```

### Task 2: Register Integration Entity in Service Provider

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

- [ ] **Step 1: Read `ClaudrielServiceProvider.php` to find where entities are registered**

Find the `register()` method and locate existing `$this->entityType(...)` calls.

- [ ] **Step 2: Add Integration entity type registration**

Add alongside existing entity registrations in `register()`:

```php
$this->entityType(new EntityType(
    id: 'integration',
    label: 'Integration',
    class: Integration::class,
    keys: ['id' => 'iid', 'uuid' => 'uuid', 'label' => 'name'],
));
```

Add `use Claudriel\Entity\Integration;` to imports if not already present.

- [ ] **Step 3: Run full test suite to verify nothing broke**

Run: `./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat: register Integration entity type in service provider"
```

---

## Chunk 2: GoogleTokenManager

### Task 3: Create GoogleTokenManagerInterface

**Files:**
- Create: `src/Support/GoogleTokenManagerInterface.php`

- [ ] **Step 1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

interface GoogleTokenManagerInterface
{
    /**
     * Returns a valid access token for the given account.
     *
     * Refreshes transparently if expired.
     *
     * @throws \RuntimeException if no active integration or refresh fails
     */
    public function getValidAccessToken(string $accountId): string;

    /**
     * Check if an account has an active Google integration.
     */
    public function hasActiveIntegration(string $accountId): bool;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Support/GoogleTokenManagerInterface.php
git commit -m "feat: add GoogleTokenManagerInterface contract"
```

### Task 4: Implement GoogleTokenManager

**Files:**
- Create: `src/Support/GoogleTokenManager.php`
- Create: `tests/Unit/Support/GoogleTokenManagerTest.php`

- [ ] **Step 1: Write failing tests for token manager**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Entity\Integration;
use Claudriel\Support\GoogleTokenManager;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;

final class GoogleTokenManagerTest extends TestCase
{
    public function test_returns_valid_token_when_not_expired(): void
    {
        $integration = new Integration([
            'account_id' => 'acc-123',
            'provider' => 'google',
            'access_token' => 'ya29.valid',
            'refresh_token' => '1//refresh',
            'token_expires_at' => (new \DateTimeImmutable('+1 hour'))->format('c'),
            'status' => 'active',
        ]);

        $storage = $this->createMock(\Waaseyaa\Entity\Storage\EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning(['1'])
        );
        $storage->method('load')->willReturn($integration);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);
        $token = $manager->getValidAccessToken('acc-123');

        self::assertSame('ya29.valid', $token);
    }

    public function test_throws_when_no_active_integration(): void
    {
        $storage = $this->createMock(\Waaseyaa\Entity\Storage\EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning([])
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active Google integration');
        $manager->getValidAccessToken('acc-999');
    }

    public function test_has_active_integration_returns_true(): void
    {
        $storage = $this->createMock(\Waaseyaa\Entity\Storage\EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning(['1'])
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);
        self::assertTrue($manager->hasActiveIntegration('acc-123'));
    }

    public function test_has_active_integration_returns_false(): void
    {
        $storage = $this->createMock(\Waaseyaa\Entity\Storage\EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning([])
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);
        self::assertFalse($manager->hasActiveIntegration('acc-999'));
    }

    private function buildQueryReturning(array $ids): object
    {
        $query = $this->createMock(\Waaseyaa\Entity\Query\EntityQueryInterface::class);
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn($ids);

        return $query;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/Support/GoogleTokenManagerTest.php -v`
Expected: FAIL (class not found)

- [ ] **Step 3: Implement GoogleTokenManager**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\Entity\EntityTypeManager;

final class GoogleTokenManager implements GoogleTokenManagerInterface
{
    private const EXPIRY_BUFFER_SECONDS = 60;
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly string $clientId = '',
        private readonly string $clientSecret = '',
    ) {}

    public function getValidAccessToken(string $accountId): string
    {
        $integration = $this->findActiveIntegration($accountId);

        if ($integration === null) {
            throw new \RuntimeException('No active Google integration for account '.$accountId);
        }

        $expiresAt = $integration->get('token_expires_at');

        if ($expiresAt !== null && !$this->isExpired($expiresAt)) {
            return $integration->get('access_token');
        }

        $refreshToken = $integration->get('refresh_token');

        if ($refreshToken === null || $refreshToken === '') {
            $integration->set('status', 'error');
            $this->entityTypeManager->getStorage('integration')->save($integration);

            throw new \RuntimeException('No refresh token available for account '.$accountId);
        }

        return $this->refreshAccessToken($integration, $refreshToken);
    }

    public function hasActiveIntegration(string $accountId): bool
    {
        return $this->findActiveIntegration($accountId) !== null;
    }

    private function findActiveIntegration(string $accountId): ?object
    {
        $ids = $this->entityTypeManager->getStorage('integration')->getQuery()
            ->condition('account_id', $accountId)
            ->condition('provider', 'google')
            ->condition('status', 'active')
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        return $this->entityTypeManager->getStorage('integration')->load(reset($ids));
    }

    private function isExpired(string $expiresAt): bool
    {
        $expiry = new \DateTimeImmutable($expiresAt);
        $now = new \DateTimeImmutable();

        return $expiry->getTimestamp() - $now->getTimestamp() < self::EXPIRY_BUFFER_SECONDS;
    }

    private function refreshAccessToken(object $integration, string $refreshToken): string
    {
        $payload = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $ch = curl_init(self::TOKEN_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $integration->set('status', 'error');
            $this->entityTypeManager->getStorage('integration')->save($integration);

            throw new \RuntimeException(
                'Google token refresh failed for account '.$integration->get('account_id')
            );
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $integration->set('access_token', $data['access_token']);
        $integration->set(
            'token_expires_at',
            (new \DateTimeImmutable('+'.$data['expires_in'].' seconds'))->format('c'),
        );

        if (isset($data['refresh_token'])) {
            $integration->set('refresh_token', $data['refresh_token']);
        }

        $this->entityTypeManager->getStorage('integration')->save($integration);

        return $data['access_token'];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Support/GoogleTokenManagerTest.php -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/GoogleTokenManager.php src/Support/GoogleTokenManagerInterface.php tests/Unit/Support/GoogleTokenManagerTest.php
git commit -m "feat: implement GoogleTokenManager with token refresh"
```

---

## Chunk 3: GoogleOAuthController + Routes

### Task 5: Create GoogleOAuthController

**Files:**
- Create: `src/Controller/GoogleOAuthController.php`
- Create: `tests/Unit/Controller/GoogleOAuthControllerTest.php`

- [ ] **Step 1: Write failing test for redirect action**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\GoogleOAuthController;
use Claudriel\Entity\Account;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;

final class GoogleOAuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_redirect_sets_session_state_and_redirects_to_google(): void
    {
        $etm = $this->createMock(EntityTypeManager::class);
        $controller = new GoogleOAuthController(
            entityTypeManager: $etm,
            clientId: 'test-client-id',
            redirectUri: 'https://example.com/auth/google/callback',
        );

        $account = new Account(['uuid' => 'acc-uuid-1']);
        $response = $controller->redirect(account: $account);

        self::assertSame(302, $response->statusCode);
        self::assertArrayHasKey('google_oauth_state', $_SESSION);
        self::assertStringContainsString('accounts.google.com', $response->headers['Location']);
        self::assertStringContainsString('test-client-id', $response->headers['Location']);
    }

    public function test_redirect_requires_authenticated_account(): void
    {
        $etm = $this->createMock(EntityTypeManager::class);
        $controller = new GoogleOAuthController(
            entityTypeManager: $etm,
            clientId: 'test-client-id',
            redirectUri: 'https://example.com/auth/google/callback',
        );

        $response = $controller->redirect(account: null);

        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('/login', $response->headers['Location']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Controller/GoogleOAuthControllerTest.php -v`
Expected: FAIL (class not found)

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Http\RedirectResponse;
use Waaseyaa\Http\Request;
use Twig\Environment;

final class GoogleOAuthController
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        'https://www.googleapis.com/auth/calendar.freebusy',
        'https://www.googleapis.com/auth/drive.file',
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly string $clientId = '',
        private readonly string $clientSecret = '',
        private readonly string $redirectUri = '',
        private readonly ?Environment $twig = null,
    ) {}

    public function redirect(
        array $params = [],
        array $query = [],
        mixed $account = null,
        ?Request $httpRequest = null,
        ?Environment $twig = null,
    ): RedirectResponse {
        if ($account === null) {
            return new RedirectResponse('/login');
        }

        $state = bin2hex(random_bytes(32));
        $_SESSION['google_oauth_state'] = $state;

        $authUrl = self::AUTH_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return new RedirectResponse($authUrl);
    }

    public function callback(
        array $params = [],
        array $query = [],
        mixed $account = null,
        ?Request $httpRequest = null,
        ?Environment $twig = null,
    ): RedirectResponse {
        if ($account === null) {
            return new RedirectResponse('/login');
        }

        if (isset($query['error'])) {
            $_SESSION['flash_error'] = 'Google authorization denied: '.($query['error'] ?? 'unknown');
            return new RedirectResponse('/');
        }

        $expectedState = $_SESSION['google_oauth_state'] ?? null;
        unset($_SESSION['google_oauth_state']);

        if ($expectedState === null || !hash_equals($expectedState, $query['state'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';
            return new RedirectResponse('/');
        }

        $tokenData = $this->exchangeCodeForTokens($query['code'] ?? '');

        if ($tokenData === null) {
            $_SESSION['flash_error'] = 'Failed to exchange authorization code.';
            return new RedirectResponse('/');
        }

        $userInfo = $this->fetchUserInfo($tokenData['access_token']);
        $providerEmail = $userInfo['email'] ?? null;

        $this->upsertIntegration($account, $tokenData, $providerEmail);

        $_SESSION['flash_success'] = 'Google account connected'
            .($providerEmail ? ' as '.$providerEmail : '').'.';

        return new RedirectResponse('/');
    }

    private function exchangeCodeForTokens(string $code): ?array
    {
        $payload = http_build_query([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $ch = curl_init(self::TOKEN_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return null;
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    private function fetchUserInfo(string $accessToken): array
    {
        $ch = curl_init(self::USERINFO_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return [];
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    private function upsertIntegration(mixed $account, array $tokenData, ?string $providerEmail): void
    {
        $storage = $this->entityTypeManager->getStorage('integration');
        $accountId = $account->get('uuid');

        $existingIds = $storage->getQuery()
            ->condition('account_id', $accountId)
            ->condition('provider', 'google')
            ->range(0, 1)
            ->execute();

        $expiresAt = isset($tokenData['expires_in'])
            ? (new \DateTimeImmutable('+'.$tokenData['expires_in'].' seconds'))->format('c')
            : null;

        $scopes = isset($tokenData['scope'])
            ? json_encode(explode(' ', $tokenData['scope']))
            : json_encode([]);

        if ($existingIds !== []) {
            $integration = $storage->load(reset($existingIds));
            $oldScopes = $integration->get('scopes');

            $integration->set('access_token', $tokenData['access_token']);
            $integration->set('token_expires_at', $expiresAt);
            $integration->set('scopes', $scopes);
            $integration->set('status', 'active');
            $integration->set('provider_email', $providerEmail);

            if (isset($tokenData['refresh_token'])) {
                $integration->set('refresh_token', $tokenData['refresh_token']);
            }

            if ($oldScopes !== $scopes) {
                $metadata = json_decode($integration->get('metadata') ?? '{}', true) ?? [];
                $metadata['scopes_changed_at'] = (new \DateTimeImmutable())->format('c');
                $integration->set('metadata', json_encode($metadata));
            }
        } else {
            $integration = new \Claudriel\Entity\Integration([
                'uuid' => \Waaseyaa\Foundation\Uuid::v4(),
                'name' => 'google',
                'account_id' => $accountId,
                'provider' => 'google',
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'token_expires_at' => $expiresAt,
                'scopes' => $scopes,
                'status' => 'active',
                'provider_email' => $providerEmail,
                'metadata' => json_encode([
                    'token_type' => $tokenData['token_type'] ?? 'Bearer',
                ]),
            ]);
        }

        $storage->save($integration);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Controller/GoogleOAuthControllerTest.php -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Controller/GoogleOAuthController.php tests/Unit/Controller/GoogleOAuthControllerTest.php
git commit -m "feat: add GoogleOAuthController with redirect and callback"
```

### Task 6: Register Routes in Service Provider

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

- [ ] **Step 1: Read ClaudrielServiceProvider to find route registration section**

Find the `routes()` method and locate where other routes are registered.

- [ ] **Step 2: Add OAuth routes**

Add to the routes section:

```php
// Google OAuth
$router->addRoute(
    'claudriel.auth.google.redirect',
    RouteBuilder::create('/auth/google')
        ->controller(GoogleOAuthController::class.'::redirect')
        ->methods('GET')
        ->build(),
);

$googleCallbackRoute = RouteBuilder::create('/auth/google/callback')
    ->controller(GoogleOAuthController::class.'::callback')
    ->methods('GET')
    ->build();
$googleCallbackRoute->setOption('_csrf', false);
$router->addRoute('claudriel.auth.google.callback', $googleCallbackRoute);
```

Add `use Claudriel\Controller\GoogleOAuthController;` to imports.

- [ ] **Step 3: Wire GoogleOAuthController in the service container**

In the service registration section, add the controller with env-based config:

```php
$container->set(GoogleOAuthController::class, new GoogleOAuthController(
    entityTypeManager: $container->get(EntityTypeManager::class),
    clientId: $_ENV['GOOGLE_CLIENT_ID'] ?? '',
    clientSecret: $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
    redirectUri: $_ENV['GOOGLE_REDIRECT_URI'] ?? '',
));
```

- [ ] **Step 4: Wire GoogleTokenManager in the service container**

```php
$container->set(GoogleTokenManagerInterface::class, new GoogleTokenManager(
    entityTypeManager: $container->get(EntityTypeManager::class),
    clientId: $_ENV['GOOGLE_CLIENT_ID'] ?? '',
    clientSecret: $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
));
```

Add imports for `GoogleTokenManager`, `GoogleTokenManagerInterface`.

- [ ] **Step 5: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat: register Google OAuth routes and services"
```

---

## Chunk 4: Integration Test + Deploy

### Task 7: Manual Integration Test

- [ ] **Step 1: Deploy to production**

Run: `dep deploy production`

- [ ] **Step 2: Verify Google OAuth env vars are present**

SSH and check `/home/deployer/claudriel/shared/.env` contains `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI`.

- [ ] **Step 3: Test the OAuth redirect**

Navigate to `https://claudriel.northcloud.one/auth/google` while authenticated.
Expected: Redirects to Google consent screen with correct client_id and scopes.

- [ ] **Step 4: Complete Google authorization**

Authorize on Google consent screen.
Expected: Redirects back to callback with code and state params, then to dashboard with success flash.

- [ ] **Step 5: Verify Integration entity was created**

```bash
ssh jones@northcloud.one "sqlite3 /home/deployer/claudriel/shared/waaseyaa.sqlite 'SELECT account_id, provider, status, provider_email FROM integration;'"
```

- [ ] **Step 6: Test reconnect flow**

Navigate to `/auth/google` again.
Expected: Updates existing Integration (upsert), does not create duplicate.

### Task 8: Final Push

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 2: Push all commits**

```bash
git push
```
