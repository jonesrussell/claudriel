<?php

declare(strict_types=1);

namespace Claudriel\Service;

use Claudriel\Entity\Account;
use Claudriel\Entity\AccountPasswordResetToken;
use Claudriel\Service\Mail\LoggedMailTransport;
use Claudriel\Service\Mail\MailTransportInterface;
use Claudriel\Service\Mail\SendGridMailTransport;
use Waaseyaa\Entity\EntityTypeManager;

final class PasswordResetService
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?MailTransportInterface $mailTransport = null,
        private readonly ?string $appUrl = null,
        private readonly ?string $storageDir = null,
    ) {}

    /**
     * @return array{issued: bool, token: string|null}
     */
    public function requestReset(string $email): array
    {
        $account = $this->findVerifiedAccountByEmail($email);
        if (! $account instanceof Account) {
            return ['issued' => false, 'token' => null];
        }

        $token = bin2hex(random_bytes(32));
        $tokenEntity = new AccountPasswordResetToken([
            'account_uuid' => $account->get('uuid'),
            'token_hash' => hash('sha256', $token),
            'expires_at' => (new \DateTimeImmutable('+2 hours'))->format(\DateTimeInterface::ATOM),
        ]);
        $this->entityTypeManager->getStorage('account_password_reset_token')->save($tokenEntity);

        $resetUrl = rtrim($this->appUrl(), '/').'/reset-password/'.$token;
        $this->mailTransport()->send([
            'template' => 'password_reset',
            'to_email' => $account->getEmail(),
            'to_name' => (string) ($account->get('name') ?? ''),
            'subject' => 'Reset your Claudriel password',
            'text' => "Reset your Claudriel password: {$resetUrl}",
            'reset_url' => $resetUrl,
            'account_uuid' => $account->get('uuid'),
        ]);

        return ['issued' => true, 'token' => $token];
    }

    public function resetPassword(string $token, string $password): Account
    {
        $tokenEntity = $this->findActiveToken($token);
        if (! $tokenEntity instanceof AccountPasswordResetToken) {
            throw new \RuntimeException('Password reset link is invalid or expired.');
        }

        $account = $this->findAccountByUuid((string) $tokenEntity->get('account_uuid'));
        if (! $account instanceof Account) {
            throw new \RuntimeException('Account for password reset was not found.');
        }

        $account->set('password_hash', password_hash($password, PASSWORD_DEFAULT));
        $this->entityTypeManager->getStorage('account')->save($account);

        $tokenEntity->set('used_at', (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM));
        $this->entityTypeManager->getStorage('account_password_reset_token')->save($tokenEntity);

        return $account;
    }

    public function tokenIsValid(string $token): bool
    {
        return $this->findActiveToken($token) instanceof AccountPasswordResetToken;
    }

    private function findVerifiedAccountByEmail(string $email): ?Account
    {
        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('email', strtolower(trim($email)))
            ->condition('status', 'active')
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));

        return $account instanceof Account ? $account : null;
    }

    private function findAccountByUuid(string $uuid): ?Account
    {
        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('uuid', $uuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));

        return $account instanceof Account ? $account : null;
    }

    private function findActiveToken(string $token): ?AccountPasswordResetToken
    {
        $ids = $this->entityTypeManager->getStorage('account_password_reset_token')->getQuery()
            ->condition('token_hash', hash('sha256', $token))
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tokenEntity = $this->entityTypeManager->getStorage('account_password_reset_token')->load(reset($ids));
        if (! $tokenEntity instanceof AccountPasswordResetToken) {
            return null;
        }

        if ($tokenEntity->get('used_at') !== null) {
            return null;
        }

        $expiresAt = (string) ($tokenEntity->get('expires_at') ?? '');
        if ($expiresAt !== '' && new \DateTimeImmutable($expiresAt) < new \DateTimeImmutable) {
            return null;
        }

        return $tokenEntity;
    }

    private function mailTransport(): MailTransportInterface
    {
        if ($this->mailTransport instanceof MailTransportInterface) {
            return $this->mailTransport;
        }

        $storageDir = $this->storageDir
            ?? (getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage');
        $fallback = new LoggedMailTransport($storageDir.'/mail-delivery.log');

        return new SendGridMailTransport(
            apiKey: (string) ($_ENV['SENDGRID_API_KEY'] ?? getenv('SENDGRID_API_KEY') ?: ''),
            fromEmail: (string) ($_ENV['CLAUDRIEL_MAIL_FROM_EMAIL'] ?? getenv('CLAUDRIEL_MAIL_FROM_EMAIL') ?: 'hello@claudriel.ai'),
            fromName: (string) ($_ENV['CLAUDRIEL_MAIL_FROM_NAME'] ?? getenv('CLAUDRIEL_MAIL_FROM_NAME') ?: 'Claudriel'),
            fallback: $fallback,
        );
    }

    private function appUrl(): string
    {
        if ($this->appUrl !== null && trim($this->appUrl) !== '') {
            return trim($this->appUrl);
        }

        $appUrl = $_ENV['CLAUDRIEL_APP_URL'] ?? getenv('CLAUDRIEL_APP_URL') ?: 'http://localhost:9889';

        return is_string($appUrl) ? $appUrl : 'http://localhost:9889';
    }
}
