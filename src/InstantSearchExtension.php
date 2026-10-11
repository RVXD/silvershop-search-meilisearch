<?php

declare(strict_types=1);

namespace SilverShop\Search\Meilisearch;

use SilverShop\Page\Product;
use SilverShop\Search\Backend\BackendRegistry;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;

/**
 * Adds an instant-search JSON endpoint at /<search-page>/instant?q=… — fast typeahead suggestions from Meilisearch,
 * for a theme to build an autocomplete UI. Returns an empty result set when Meilisearch is not the active backend.
 *
 * @extends Extension<\SilverShop\Search\SearchPageController>
 */
class InstantSearchExtension extends Extension
{
    private static array $allowed_actions = ['instant'];

    public function instant(HTTPRequest $request): HTTPResponse
    {
        $terms = (string) $request->getVar('q');
        $results = [];

        $backend = (new BackendRegistry())->getActive();
        if ($backend instanceof MeilisearchBackend) {
            foreach ($backend->suggest($terms) as $product) {
                if ($product instanceof Product) {
                    $results[] = $this->row($product);
                }
            }
        }

        $response = HTTPResponse::create((string) json_encode(['query' => $terms, 'results' => $results]));
        $response->addHeader('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function row(Product $product): array
    {
        $image = $product->Image();

        return [
            'title' => (string) $product->Title,
            'link' => (string) $product->Link(),
            'price' => (string) $product->obj('Price')->Nice(),
            'image' => ($image && $image->exists()) ? (string) $image->ScaleWidth(100)->getURL() : '',
        ];
    }
}
