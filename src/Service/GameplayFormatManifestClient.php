<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the Altered Reunion formats manifest and individual format definitions.
 *
 * Manifest shape: [{id, name, path, version}, ...]
 * Format definition shape: {id, version, included_refs?, excluded_sets?, excluded_refs?}
 */
class GameplayFormatManifestClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @return array<int, array{id: string, name: string, path: string, version: int}>
     */
    public function fetchManifest(): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/manifest.json');

        return $response->toArray();
    }

    /**
     * @return array{id: string, version: int, included_refs?: string[], excluded_sets?: string[], excluded_refs?: string[]}
     */
    public function fetchDefinition(string $path): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/' . $path);

        return $response->toArray();
    }
}
