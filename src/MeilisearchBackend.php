<?php

declare(strict_types=1);

namespace SilverShop\Search\Meilisearch;

use SilverShop\Page\Product;
use SilverShop\Search\Backend\SearchBackend;
use SilverShop\Search\Facet\FacetApplier;
use SilverShop\Search\Query\SearchQuery;
use SilverShop\Search\Query\SearchResults;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Meilisearch-backed {@link SearchBackend}: typo-tolerant, relevance-ranked product search.
 *
 * Products are indexed (by {@link index()} / the reindex task / {@link ProductSyncExtension}); a query lets Meilisearch
 * do the typo-tolerant text match + relevance ranking, then reuses silvershop/search's {@link FacetApplier} to apply
 * the selected facets and count their options over the matched set — so every registered facet works, unchanged, with
 * no per-facet engine mapping. Results come back in Meili's rank order (for relevance) and the SearchPage controller
 * paginates them (no change needed in silvershop/search). Native Meili facetDistribution is a later optimisation.
 */
class MeilisearchBackend implements SearchBackend
{
    use Configurable;
    use Injectable;

    private static string $index_name = 'products';

    /** Max ranked hits fetched per query (the controller paginates this window). */
    private static int $result_window = 1000;

    /** Document fields searched for free text, highest-weighted first (Meilisearch ranks by this order). */
    private static array $searchable_attributes = ['Title', 'InternalItemID', 'Model', 'Content'];

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
        // Meilisearch matches the free text (typo-tolerant, ranked); facet filtering + counts reuse the framework.
        if ($query->hasTerms()) {
            $rankedIds = $this->matchIds($query->terms);
            $base = Product::get()->filter('ID', $rankedIds ?: [0]);
        } else {
            $rankedIds = [];
            $base = Product::get();
        }

        $applied = FacetApplier::apply($base, $query);
        $products = $applied['products'];

        // Keep Meili's relevance order for a text search with no explicit sort; otherwise sort in the ORM.
        $hits = ($query->hasTerms() && $query->sort === '')
            ? $this->rankOrder($products, $rankedIds)
            : $this->applySort($products, $query->sort);

        return new SearchResults($hits, $hits->count(), $applied['facets']);
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
            'searchableAttributes' => array_values((array) self::config()->get('searchable_attributes')),
            'displayedAttributes' => ['id'],
            'synonyms' => $this->synonyms(),
        ]);
    }

    /**
     * CMS-managed synonyms (SiteConfig.SearchSynonyms) as a Meilisearch synonym map. One group per line,
     * comma-separated; every term in a group becomes a mutual synonym of the others.
     *
     * @return array<string, array<int, string>>
     */
    public function synonyms(): array
    {
        $raw = (string) SiteConfig::current_site_config()->SearchSynonyms;
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $terms = array_values(array_filter(array_map('trim', explode(',', $line)), 'strlen'));
            if (count($terms) < 2) {
                continue;
            }
            $lower = array_map('mb_strtolower', $terms);
            foreach ($lower as $term) {
                $map[$term] = array_values(array_diff($lower, [$term]));
            }
        }

        return $map;
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
     * @return array<int, int> Meili-ranked product ids for the free-text terms.
     */
    private function matchIds(string $terms): array
    {
        $response = $this->client()->search([
            'q' => $terms,
            'limit' => max(1, (int) self::config()->get('result_window')),
            'attributesToRetrieve' => ['id'],
        ]);

        return array_values(array_filter(array_map(
            static fn ($hit): int => (int) ($hit['id'] ?? 0),
            $response['hits'] ?? []
        )));
    }

    private function applySort(DataList $products, string $sort): DataList
    {
        return match ($sort) {
            'price_asc' => $products->sort('BasePrice', 'ASC'),
            'price_desc' => $products->sort('BasePrice', 'DESC'),
            'newest' => $products->sort('Created', 'DESC'),
            'title' => $products->sort('Title', 'ASC'),
            default => $products,
        };
    }

    /**
     * Order the facet-filtered products by the Meilisearch rank.
     *
     * @param array<int, int> $rankedIds
     */
    private function rankOrder(DataList $products, array $rankedIds): ArrayList
    {
        $byID = [];
        foreach ($products as $product) {
            $byID[(int) $product->ID] = $product;
        }
        $list = ArrayList::create();
        foreach ($rankedIds as $id) {
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
