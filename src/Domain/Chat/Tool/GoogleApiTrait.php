<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

/**
 * Shared Google API HTTP helpers for agent tools.
 *
 * Uses file_get_contents with stream context (no curl_exec per pre-push hook).
 */
trait GoogleApiTrait
{
    private function googleApiGet(string $url, string $accessToken): array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer {$accessToken}\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'Google API request failed'];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid Google API response'];
    }

    private function googleApiPost(string $url, string $accessToken, array $data): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\n",
                'content' => json_encode($data, JSON_THROW_ON_ERROR),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'Google API request failed'];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid Google API response'];
    }
}
