<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Twig\Environment;
use Waaseyaa\SSR\SsrResponse;

final class PublicHomepageController
{
    public function __construct(
        private readonly ?Environment $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $context = [
            'primary_cta_href' => '/signup',
            'secondary_cta_href' => '/login',
            'headline' => 'Run your day before it runs you.',
            'subheadline' => 'Claudriel turns your schedule, commitments, and active work into one focused operating surface.',
            'proof_points' => [
                'See the day clearly before the first meeting starts.',
                'Turn signup into a ready tenant and workspace without manual provisioning.',
                'Keep chat, brief, schedule intelligence, and workspace actions in one flow.',
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
}
