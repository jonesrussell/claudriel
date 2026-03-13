<?php

declare(strict_types=1);

namespace Claudriel\Controller\Governance;

use Claudriel\Service\Governance\CodifiedContextIntegrityScanner;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\SSR\SsrResponse;

final class CodifiedContextIntegrityController
{
    public function __construct(
        private readonly ?Environment $twig = null,
        private readonly ?string $projectRoot = null,
    ) {}

    public function index(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $payload = $this->buildPayload();

        if ($this->twig !== null) {
            $html = $this->twig->render('governance/integrity/index.twig', $payload);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json($payload);
    }

    public function jsonView(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        return $this->json($this->buildPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $scanner = new CodifiedContextIntegrityScanner($this->projectRoot ?? __DIR__.'/../../..');
        $scan = $scanner->scan();
        $classifications = $scanner->classifyIssues($scan['issues']);

        return [
            'generated_at' => $scan['generated_at'],
            'issues' => $scan['issues'],
            'classifications' => $classifications,
            'summary' => $scanner->summarize($scan['issues']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
