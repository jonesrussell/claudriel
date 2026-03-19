<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\SSR\SsrResponse;

final class PublicHomepageController
{
    public function __construct(
        private readonly ?Environment $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): RedirectResponse|SsrResponse
    {
        if ($account instanceof AuthenticatedAccount) {
            return new RedirectResponse($this->appUrl($account), 302);
        }

        $context = [
            'primary_cta_href' => '/signup',
            'primary_cta_label' => 'Join the waitlist',
            'secondary_cta_href' => '/login',
            'headline' => 'Run your day before it runs you.',
            'subheadline' => 'Claudriel turns your schedule, commitments, and active work into one focused operating surface. We\'re in early access.',
            'proof_points' => [
                'See the day clearly before the first meeting starts.',
                'Keep commitments, drift, and workspace actions in one tenant-aware shell.',
                'Let chat, brief, and schedule guidance stay in sync as work moves.',
            ],
        ];

        if ($this->twig === null) {
            return new SsrResponse(
                content: json_encode($context, JSON_THROW_ON_ERROR),
                statusCode: 200,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        return new SsrResponse(
            content: $this->twig->render('public/homepage.twig', $context),
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function appUrl(AuthenticatedAccount $account): string
    {
        $query = [];
        if ($account->getTenantId() !== null && $account->getTenantId() !== '') {
            $query['tenant_id'] = $account->getTenantId();
        }

        $queryString = $query === [] ? '' : '?'.http_build_query($query);

        return '/app'.$queryString;
    }
}
