<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class SettingsController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
    ) {}

    public function status(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $authenticatedAccount = $this->resolveAccount($account);

        if ($authenticatedAccount === null) {
            return $this->json(['connected' => false, 'email' => null, 'connected_at' => null]);
        }

        $accountUuid = $authenticatedAccount->getUuid();

        $integration = $this->findGoogleIntegration($accountUuid);

        if ($integration === null) {
            return $this->json(['connected' => false, 'email' => null, 'connected_at' => null]);
        }

        return $this->json([
            'connected' => true,
            'email' => $integration->get('provider_email') ?? $integration->get('name') ?? null,
            'connected_at' => $integration->get('created_at'),
        ]);
    }

    public function githubStatus(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $authenticatedAccount = $this->resolveAccount($account);

        if ($authenticatedAccount === null) {
            return $this->json(['connected' => false, 'username' => null, 'connected_at' => null]);
        }

        $integration = $this->findIntegration($authenticatedAccount->getUuid(), 'github');

        if ($integration === null) {
            return $this->json(['connected' => false, 'username' => null, 'connected_at' => null]);
        }

        return $this->json([
            'connected' => true,
            'username' => $integration->get('provider_email') ?? $integration->get('name') ?? null,
            'connected_at' => $integration->get('created_at'),
        ]);
    }

    public function githubDisconnect(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $authenticatedAccount = $this->resolveAccount($account);

        if ($authenticatedAccount === null) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $integration = $this->findIntegration($authenticatedAccount->getUuid(), 'github');

        if ($integration === null) {
            return $this->json(['error' => 'No GitHub connection found'], 404);
        }

        $integration->set('status', 'disconnected');
        $integration->set('access_token', null);
        $this->entityTypeManager->getStorage('integration')->save($integration);

        return $this->json(['disconnected' => true]);
    }

    public function disconnect(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $authenticatedAccount = $this->resolveAccount($account);

        if ($authenticatedAccount === null) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $accountUuid = $authenticatedAccount->getUuid();

        $integration = $this->findGoogleIntegration($accountUuid);

        if ($integration === null) {
            return $this->json(['error' => 'No Google connection found'], 404);
        }

        // Revoke the token at Google
        $accessToken = $integration->get('access_token');
        if (is_string($accessToken) && $accessToken !== '') {
            $this->revokeGoogleToken($accessToken);
        }

        // Mark integration as disconnected and clear tokens
        $integration->set('status', 'disconnected');
        $integration->set('access_token', null);
        $integration->set('refresh_token', null);
        $integration->set('token_expires_at', null);
        $this->entityTypeManager->getStorage('integration')->save($integration);

        return $this->json(['disconnected' => true]);
    }

    public function show(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $authenticatedAccount = $this->resolveAccount($account);
        $accountUuid = $authenticatedAccount?->getUuid() ?? '';

        $googleIntegration = $accountUuid !== '' ? $this->findGoogleIntegration($accountUuid) : null;
        $githubIntegration = $accountUuid !== '' ? $this->findIntegration($accountUuid, 'github') : null;

        $googleConnected = $googleIntegration !== null;
        $googleEmail = $googleConnected ? ($googleIntegration->get('provider_email') ?? $googleIntegration->get('name') ?? '') : '';
        $googleConnectedAt = $googleConnected ? ($googleIntegration->get('created_at') ?? '') : '';

        $githubConnected = $githubIntegration !== null;
        $githubUsername = $githubConnected ? ($githubIntegration->get('provider_email') ?? $githubIntegration->get('name') ?? '') : '';
        $githubConnectedAt = $githubConnected ? ($githubIntegration->get('created_at') ?? '') : '';

        if ($this->twig !== null) {
            $html = $this->twig->render('settings.html.twig', [
                'google_connected' => $googleConnected,
                'google_email' => $googleEmail,
                'google_connected_at' => $googleConnectedAt,
                'github_connected' => $githubConnected,
                'github_username' => $githubUsername,
                'github_connected_at' => $githubConnectedAt,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json([
            'google' => [
                'connected' => $googleConnected,
                'email' => $googleEmail,
                'connected_at' => $googleConnectedAt,
            ],
            'github' => [
                'connected' => $githubConnected,
                'username' => $githubUsername,
                'connected_at' => $githubConnectedAt,
            ],
        ]);
    }

    private function findGoogleIntegration(string $accountUuid): ?object
    {
        return $this->findIntegration($accountUuid, 'google');
    }

    private function findIntegration(string $accountUuid, string $provider): ?object
    {
        $ids = $this->entityTypeManager->getStorage('integration')->getQuery()
            ->condition('account_id', $accountUuid)
            ->condition('provider', $provider)
            ->condition('status', 'active')
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        return $this->entityTypeManager->getStorage('integration')->load(reset($ids));
    }

    private function revokeGoogleToken(string $token): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['token' => $token]),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        file_get_contents('https://oauth2.googleapis.com/revoke', false, $context);
    }

    private function resolveAccount(AccountInterface $account): ?AuthenticatedAccount
    {
        if ($account instanceof AuthenticatedAccount) {
            return $account;
        }

        return (new AuthenticatedAccountSessionResolver($this->entityTypeManager))->resolve();
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
