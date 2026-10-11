<?php

declare(strict_types=1);

namespace SilverShop\Search\Meilisearch;

use GuzzleHttp\Client;
use SilverStripe\Core\Injector\Injectable;

/**
 * Thin Meilisearch REST client (Guzzle). Deliberately minimal — just the few endpoints the backend needs — to avoid
 * the meilisearch-php SDK's php-http/discovery dependency, which the SilverStripe stack disables.
 */
class MeiliClient
{
    use Injectable;

    private Client $http;

    public function __construct(string $host, string $key, private string $index)
    {
        $this->http = new Client([
            'base_uri' => rtrim($host, '/') . '/',
            'headers' => $key !== '' ? ['Authorization' => 'Bearer ' . $key] : [],
            'timeout' => 10,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function search(array $body): array
    {
        return $this->json('POST', "indexes/{$this->index}/search", $body);
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    public function addDocuments(array $documents): void
    {
        if ($documents) {
            // primaryKey=id is explicit: Meili can't infer it (both `id` and `InternalItemID` end in "id").
            $this->json('POST', "indexes/{$this->index}/documents?primaryKey=id", $documents);
        }
    }

    public function deleteDocument(int|string $id): void
    {
        $this->http->request('DELETE', "indexes/{$this->index}/documents/{$id}");
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function updateSettings(array $settings): void
    {
        $this->json('PATCH', "indexes/{$this->index}/settings", $settings);
    }

    public function health(): bool
    {
        try {
            $this->http->request('GET', 'health');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed> $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $uri, array $body): array
    {
        $response = $this->http->request($method, $uri, ['json' => $body]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }
}
