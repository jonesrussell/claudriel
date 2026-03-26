<?php

declare(strict_types=1);

namespace Claudriel\Controller\Pipeline;

use Claudriel\Domain\Pipeline\NorthCloudLeadFetcher;
use Claudriel\Domain\Pipeline\SectorNormalizer;
use Claudriel\Entity\FilteredProspect;
use Claudriel\Entity\PipelineConfig;
use Claudriel\Ingestion\Handler\ProspectIngestHandler;
use Claudriel\Ingestion\NorthCloudLeadNormalizer;
use Claudriel\Pipeline\LeadFilterStep;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class PipelineFetchController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?object $aiClient = null,
    ) {}

    public function fetch(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): JsonResponse
    {
        $authError = $this->requireApiKey($httpRequest);
        if ($authError !== null) {
            return $authError;
        }

        $workspaceUuid = $params['workspace_uuid'] ?? '';
        if ($workspaceUuid === '') {
            return new JsonResponse(['error' => 'workspace_uuid is required'], 400);
        }

        $config = $this->loadPipelineConfig($workspaceUuid);
        if (! $config instanceof PipelineConfig) {
            return new JsonResponse(['error' => 'No PipelineConfig found for workspace'], 404);
        }

        $tenantId = (string) ($config->get('tenant_id') ?? $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');

        $fetcher = new NorthCloudLeadFetcher;
        $normalizer = new NorthCloudLeadNormalizer;
        $handler = new ProspectIngestHandler($this->entityTypeManager);

        $hits = $fetcher->fetch($config);
        $allowedSectors = $this->decodeSectors($config);
        $filterStep = $this->aiClient !== null ? new LeadFilterStep($this->aiClient) : null;
        $autoQualify = (bool) ($config->get('auto_qualify') ?? true);
        $companyProfile = (string) ($config->get('company_profile') ?? '');

        $imported = 0;
        $skipped = 0;
        $filtered = 0;

        foreach ($hits as $hit) {
            $title = (string) ($hit['title'] ?? $hit['name'] ?? '');
            $description = (string) ($hit['description'] ?? '');
            $sector = (string) ($hit['sector'] ?? $hit['category'] ?? '');

            if ($filterStep !== null && $autoQualify) {
                $filterResult = $filterStep->process([
                    'title' => $title,
                    'description' => $description,
                    'sector' => $sector,
                    'allowed_sectors' => $allowedSectors,
                    'company_profile' => $companyProfile,
                ], new PipelineContext('pipeline-fetch', time()));

                if ($filterResult->success) {
                    $filterData = $filterResult->output;
                    if (! ($filterData['relevant'] ?? true)) {
                        $this->saveFilteredProspect(
                            $hit,
                            (string) ($filterData['reject_reason'] ?? 'Not relevant'),
                            $workspaceUuid,
                            $tenantId,
                        );
                        $filtered++;

                        continue;
                    }
                }
            }

            $data = $normalizer->normalize($hit, $tenantId, $workspaceUuid);
            $result = $handler->handle($data);

            if (($result['status'] ?? '') === 'created') {
                $imported++;
            } else {
                $skipped++;
            }
        }

        return new JsonResponse([
            'imported' => $imported,
            'skipped' => $skipped,
            'filtered' => $filtered,
            'total' => count($hits),
        ]);
    }

    private function requireApiKey(?Request $httpRequest): ?JsonResponse
    {
        if (! $httpRequest instanceof Request) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $header = $httpRequest->headers->get('Authorization', '');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $token = substr($header, 7);
        $validKey = $_ENV['CLAUDRIEL_API_KEY'] ?? getenv('CLAUDRIEL_API_KEY') ?: '';

        if ($token === '' || $validKey === '' || $token !== $validKey) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return null;
    }

    private function loadPipelineConfig(string $workspaceUuid): ?PipelineConfig
    {
        $storage = $this->entityTypeManager->getStorage('pipeline_config');
        $query = $storage->getQuery();
        $query->accessCheck(false);
        $query->condition('workspace_uuid', $workspaceUuid);
        $ids = $query->execute();

        $entity = $ids !== [] ? $storage->load(reset($ids)) : null;

        return $entity instanceof PipelineConfig ? $entity : null;
    }

    /**
     * @return list<string>
     */
    private function decodeSectors(PipelineConfig $config): array
    {
        $raw = (string) ($config->get('sectors') ?? '');
        if ($raw === '') {
            return SectorNormalizer::CANONICAL_SECTORS;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : SectorNormalizer::CANONICAL_SECTORS;
    }

    private function saveFilteredProspect(array $hit, string $reason, string $workspaceUuid, string $tenantId): void
    {
        $storage = $this->entityTypeManager->getStorage('filtered_prospect');
        $entity = new FilteredProspect([
            'external_id' => (string) ($hit['id'] ?? $hit['slug'] ?? ''),
            'title' => (string) ($hit['title'] ?? $hit['name'] ?? ''),
            'description' => (string) ($hit['description'] ?? ''),
            'reject_reason' => $reason,
            'import_batch' => date('Y-m-d\TH:i:s'),
            'workspace_uuid' => $workspaceUuid,
            'tenant_id' => $tenantId,
        ]);
        $storage->save($entity);
    }
}
