<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\PublicPasswordResetController;
use Claudriel\Entity\Account;
use Claudriel\Entity\AccountPasswordResetToken;
use Claudriel\Service\Mail\MailTransportInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class PublicPasswordResetControllerTest extends TestCase
{
    public function test_reset_request_issues_token_and_sends_mail(): void
    {
        $transport = new InMemoryPasswordResetMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();
        $this->seedVerifiedAccount($entityTypeManager);
        $controller = $this->controller($entityTypeManager, $transport);

        $response = $controller->requestReset(
            httpRequest: Request::create('/forgot-password', 'POST', ['email' => 'test@example.com']),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/forgot-password/check-email?email=test%40example.com', $response->getTargetUrl());
        self::assertCount(1, $transport->messages);

        $tokens = $entityTypeManager->getStorage('account_password_reset_token')->loadMultiple(
            $entityTypeManager->getStorage('account_password_reset_token')->getQuery()->execute(),
        );
        self::assertCount(1, $tokens);
    }

    public function test_valid_reset_token_updates_password_once(): void
    {
        $transport = new InMemoryPasswordResetMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();
        $this->seedVerifiedAccount($entityTypeManager);
        $controller = $this->controller($entityTypeManager, $transport);

        $controller->requestReset(
            httpRequest: Request::create('/forgot-password', 'POST', ['email' => 'test@example.com']),
        );

        $token = basename((string) $transport->messages[0]['reset_url']);
        $form = $controller->resetForm(['token' => $token]);
        self::assertSame(200, $form->statusCode);
        self::assertStringContainsString('Choose a new password', $form->content);

        $complete = $controller->resetPassword(
            params: ['token' => $token],
            httpRequest: Request::create('/reset-password/'.$token, 'POST', ['password' => 'brand new password']),
        );
        self::assertInstanceOf(RedirectResponse::class, $complete);
        self::assertSame('/reset-password/complete?status=complete', $complete->getTargetUrl());

        $accounts = $entityTypeManager->getStorage('account')->loadMultiple(
            $entityTypeManager->getStorage('account')->getQuery()->execute(),
        );
        $account = array_values($accounts)[0] ?? null;
        self::assertInstanceOf(Account::class, $account);
        self::assertTrue(password_verify('brand new password', (string) $account->get('password_hash')));

        $retry = $controller->resetPassword(
            params: ['token' => $token],
            httpRequest: Request::create('/reset-password/'.$token, 'POST', ['password' => 'should fail']),
        );
        self::assertSame('/reset-password/complete?status=invalid', $retry->getTargetUrl());
    }

    private function controller(?EntityTypeManager $entityTypeManager = null, ?MailTransportInterface $transport = null): PublicPasswordResetController
    {
        return new PublicPasswordResetController(
            $entityTypeManager ?? $this->buildEntityTypeManager(),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
            $transport,
            'https://claudriel.test',
            sys_get_temp_dir().'/claudriel-password-reset-tests',
        );
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'account_password_reset_token',
            label: 'Account Password Reset Token',
            class: AccountPasswordResetToken::class,
            keys: ['id' => 'aprtid', 'uuid' => 'uuid'],
        ));

        return $entityTypeManager;
    }

    private function seedVerifiedAccount(EntityTypeManager $entityTypeManager): void
    {
        $entityTypeManager->getStorage('account')->save(new Account([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
        ]));
    }
}

final class InMemoryPasswordResetMailTransport implements MailTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public function send(array $message): array
    {
        $this->messages[] = $message;

        return ['transport' => 'memory', 'status' => 'queued'];
    }
}
