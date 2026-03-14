<?php

declare(strict_types=1);

namespace Claudriel\Service;

final class SidecarWorkspaceBootstrapService
{
    /** @var null|callable(string, string): array<string, mixed> */
    private mixed $responder;

    /**
     * @param  null|callable(string, string): array<string, mixed>  $responder
     */
    public function __construct(
        private readonly ?string $sidecarUrl = null,
        private readonly ?string $sidecarKey = null,
        mixed $responder = null,
    ) {
        $this->responder = $responder;
    }

    /**
     * @return array{state: string, tenant_id: string, workspace_id: string}
     */
    public function bootstrap(string $tenantId, string $workspaceUuid): array
    {
        if (is_callable($this->responder)) {
            $result = ($this->responder)($tenantId, $workspaceUuid);

            return [
                'state' => (string) ($result['state'] ?? 'created'),
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceUuid,
            ];
        }

        $sidecarUrl = $this->sidecarUrl ?? ($_ENV['SIDECAR_URL'] ?? getenv('SIDECAR_URL') ?: '');
        $sidecarKey = $this->sidecarKey ?? ($_ENV['CLAUDRIEL_SIDECAR_KEY'] ?? getenv('CLAUDRIEL_SIDECAR_KEY') ?: '');

        if (! is_string($sidecarUrl) || trim($sidecarUrl) === '' || ! is_string($sidecarKey) || trim($sidecarKey) === '') {
            return [
                'state' => 'skipped',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceUuid,
            ];
        }

        $payload = json_encode([
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceUuid,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init(rtrim($sidecarUrl, '/').'/bootstrap/workspace');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$sidecarKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException($error !== '' ? $error : 'Sidecar bootstrap failed.');
        }

        $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);

        return [
            'state' => (string) ($decoded['state'] ?? 'created'),
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceUuid,
        ];
    }
}
