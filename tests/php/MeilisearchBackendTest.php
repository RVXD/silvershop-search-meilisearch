<?php

namespace SilverShop\Search\Meilisearch\Tests;

use SilverShop\Page\Product;
use SilverShop\Search\Meilisearch\MeilisearchBackend;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\SiteConfig\SiteConfig;

class MeilisearchBackendTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    public function testDocumentShapeStripsTagsAndCastsPrice(): void
    {
        $product = Product::create([
            'Title' => 'Red Shirt',
            'InternalItemID' => 'SKU1',
            'Model' => 'M1',
            'Content' => '<p>Nice shirt</p>',
            'BasePrice' => 19.95,
        ]);
        $product->write();

        $doc = (new MeilisearchBackend())->document($product);

        $this->assertSame($product->ID, $doc['id']);
        $this->assertSame('Red Shirt', $doc['Title']);
        $this->assertSame('SKU1', $doc['InternalItemID']);
        $this->assertSame('Nice shirt', $doc['Content']);
        $this->assertEqualsWithDelta(19.95, $doc['BasePrice'], 0.001);
    }

    public function testQueryReturnsProductsInMeiliRankOrderWithFacets(): void
    {
        $a = Product::create(['Title' => 'A', 'BasePrice' => 1]);
        $a->write();
        $b = Product::create(['Title' => 'B', 'BasePrice' => 1]);
        $b->write();
        $c = Product::create(['Title' => 'C', 'BasePrice' => 1]);
        $c->write();

        // Meili ranks C then A; B did not match.
        $backend = (new MeilisearchBackend())->setClient(new FakeMeiliClient([['id' => $c->ID], ['id' => $a->ID]]));
        $results = $backend->query(new SearchQuery(terms: 'anything'));

        $this->assertSame([$c->ID, $a->ID], $results->hits->column('ID'), 'rank order preserved');
        $this->assertSame(2, $results->total, 'B is excluded (not matched by Meili)');
        $this->assertContains('price', array_column($results->facets, 'name'), 'facets come from the framework');
    }

    public function testBrowseWithoutTermsReturnsAllProducts(): void
    {
        Product::create(['Title' => 'A', 'BasePrice' => 1])->write();
        Product::create(['Title' => 'B', 'BasePrice' => 1])->write();

        // No terms → no Meili call; the full catalogue is faceted via the framework.
        $results = (new MeilisearchBackend())->setClient(new FakeMeiliClient())->query(new SearchQuery());

        $this->assertSame(2, $results->total);
        $this->assertNotEmpty($results->facets);
    }

    public function testIndexAndRemoveCallTheClient(): void
    {
        $product = Product::create(['Title' => 'P', 'BasePrice' => 1]);
        $product->write();

        $fake = new FakeMeiliClient();
        $backend = (new MeilisearchBackend())->setClient($fake);

        $backend->index(ArrayList::create([$product]));
        $this->assertCount(1, $fake->added);
        $this->assertSame($product->ID, $fake->added[0]['id']);

        $backend->remove((int) $product->ID);
        $this->assertSame([$product->ID], $fake->deleted);
    }

    public function testSynonymsParseIntoAMutualMeiliMap(): void
    {
        $config = SiteConfig::current_site_config();
        $config->SearchSynonyms = "tv, television, telly\nlaptop, notebook\nignored";
        $config->write();

        $map = (new MeilisearchBackend())->synonyms();

        $this->assertSame(['television', 'telly'], $map['tv']);
        $this->assertSame(['tv', 'telly'], $map['television']);
        $this->assertSame(['notebook'], $map['laptop']);
        $this->assertArrayNotHasKey('ignored', $map, 'single-term lines are skipped');
    }

    public function testSuggestReturnsTopProductsInRankOrder(): void
    {
        $a = Product::create(['Title' => 'A', 'BasePrice' => 1]);
        $a->write();
        $b = Product::create(['Title' => 'B', 'BasePrice' => 1]);
        $b->write();

        $backend = (new MeilisearchBackend())->setClient(new FakeMeiliClient([['id' => $b->ID], ['id' => $a->ID]]));

        $this->assertSame([$b->ID, $a->ID], $backend->suggest('x')->column('ID'));
        $this->assertCount(0, $backend->suggest(''), 'empty terms = no suggestions');
    }

    public function testNameAndCapabilities(): void
    {
        $backend = new MeilisearchBackend();

        $this->assertSame('meilisearch', $backend->getName());
        $this->assertTrue($backend->supportsTypoTolerance());
        $this->assertTrue($backend->supportsSynonyms());
        $this->assertTrue($backend->supportsInstant());
    }
}
