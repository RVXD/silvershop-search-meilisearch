<?php

declare(strict_types=1);

namespace SilverShop\Search\Meilisearch;

use SilverShop\Page\Product;
use SilverShop\Search\Backend\SearchBackend;
use SilverShop\Search\Query\SearchQuery;
use SilverShop\Search\Query\SearchResults;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;

/**
 * Meilisearch-backed {@link SearchBackend}: typo-tolerant, relevance-ranked product search.
 *
 * Products are indexed (by {@link index()} / the reindex task / {@link ProductSyncExtension}); queries hit Meilisearch
 * and return the matching products in Meili's rank order as an {@link ArrayList}, which the SearchPage controller then
 * paginates (no change needed in silvershop/search). Facet mapping arrives in a later slice; for now facets are empty.
 */
class MeilisearchBackend implements SearchBackend
{
    use Configurable;
    use Injectable;

    private static string $index_name = 'products';

    /** Max ranked hits fetched per query (the controller paginates this window). */
    private static int $result_window = 1000;

    /** Document fields searched for free text, highest-weighted first. */
    private const SEARCHABLE = ['Title', 'InternalItemID', 'Model', 'Content'];

    /** Document fields available to sort on. */
    private const SORTABLE = ['BasePrice', 'CreatedTimestamp', 'Title'];

    private ?MeiliClient $client = null;

    public function setClient(MeiliClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getName(): string
    {
        return 'meilisearch';
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Meilisearch');
    }

    public function isConfigured(): bool
    {
        return (string) Environment::getEnv('SS_MEILISEARCH_HOST') !== '';
    }

    public function query(SearchQuery $query): SearchResults
    {
        $body = [
            'q' => $query->terms,
            'limit' => max(1, (int) self::config()->get('result_window')),
            'attributesToRetrieve' => ['id'],
        ];
        if ($sort = $this->sortFor($query->sort)) {
            $body['sort'] = $sort;
        }

        $response = $this->client()->search($body);
        $ids = array_values(array_filter(array_map(
            static fn ($hit): int => (int) ($hit['id'] ?? 0),
            $response['hits'] ?? []
        )));

        $hits = $this->productsInRankOrder($ids);

        return new SearchResults($hits, $hits->count(), []);
    }

    public function index(SS_List $products): void
    {
        $documents = [];
        foreach ($products as $product) {
            if ($product instanceof Product) {
                $documents[] = $this->document($product);
            }
        }
        $this->client()->addDocuments($documents);
    }

    public function remove(int $productID): void
    {
        $this->client()->deleteDocument($productID);
    }

    /**
     * Push the index settings (searchable / sortable attributes). Run by the reindex task.
     */
    public function pushSettings(): void
    {
        $this->client()->updateSettings([
            'searchableAttributes' => self::SEARCHABLE,
            'sortableAttributes' => self::SORTABLE,
            'displayedAttributes' => ['id'],
        ]);
    }

    public function supportsTypoTolerance(): bool
    {
        return true;
    }

    public function supportsSynonyms(): bool
    {
        return true;
    }

    public function supportsInstant(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Product $product): array
    {
        return [
            'id' => (int) $product->ID,
            'Title' => (string) $product->Title,
            'InternalItemID' => (string) $product->InternalItemID,
            'Model' => (string) $product->Model,
            'Content' => trim(strip_tags((string) $product->Content)),
            'BasePrice' => (float) $product->BasePrice,
            'CreatedTimestamp' => (int) strtotime((string) $product->Created),
        ];
    }

    /**
     * @return array<int, string> Meilisearch sort expressions (empty = relevance).
     */
    public function sortFor(string $sort): array
    {
        return match ($sort) {
            'price_asc' => ['BasePrice:asc'],
            'price_desc' => ['BasePrice:desc'],
            'newest' => ['CreatedTimestamp:desc'],
            'title' => ['Title:asc'],
            default => [],
        };
    }

    /**
     * Fetch the (still-published) products for the given ids, preserving the Meilisearch rank order.
     *
     * @param array<int, int> $ids
     */
    public function productsInRankOrder(array $ids): ArrayList
    {
        $list = ArrayList::create();
        if (!$ids) {
            return $list;
        }

        $byID = [];
        foreach (Product::get()->filter('ID', $ids) as $product) {
            $byID[(int) $product->ID] = $product;
        }
        foreach ($ids as $id) {
            if (isset($byID[$id])) {
                $list->push($byID[$id]);
            }
        }

        return $list;
    }

    private function client(): MeiliClient
    {
        return $this->client ??= MeiliClient::create(
            (string) Environment::getEnv('SS_MEILISEARCH_HOST'),
            (string) Environment::getEnv('SS_MEILISEARCH_KEY'),
            (string) self::config()->get('index_name')
        );
    }
}
