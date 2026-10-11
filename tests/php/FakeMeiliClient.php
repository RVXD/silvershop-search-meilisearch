<?php

namespace SilverShop\Search\Meilisearch\Tests;

use SilverShop\Search\Meilisearch\MeiliClient;

/**
 * In-memory stand-in for {@link MeiliClient} — records calls and returns canned search hits, so the backend can be
 * tested without a running Meilisearch. Deliberately does not call the parent constructor (no HTTP client).
 */
class FakeMeiliClient extends MeiliClient
{
    /** @var array<int, array<string, mixed>> */
    public array $added = [];

    /** @var array<int, int|string> */
    public array $deleted = [];

    /** @var array<string, mixed>|null */
    public ?array $settings = null;

    /**
     * @param array<int, array<string, mixed>> $searchHits
     */
    public function __construct(private array $searchHits = [])
    {
    }

    public function search(array $body): array
    {
        return ['hits' => $this->searchHits];
    }

    public function addDocuments(array $documents): void
    {
        $this->added = array_merge($this->added, $documents);
    }

    public function deleteDocument(int|string $id): void
    {
        $this->deleted[] = $id;
    }

    public function updateSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function health(): bool
    {
        return true;
    }
}
