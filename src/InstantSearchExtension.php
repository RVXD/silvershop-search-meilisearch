<?php

declare(strict_types=1);

namespace SilverShop\Search\Meilisearch;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
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

        try {
            $backend = (new BackendRegistry())->getActive();
            if ($backend instanceof MeilisearchBackend) {
                foreach ($backend->suggest($terms) as $product) {
                    if ($product instanceof Product) {
                        $results[] = $this->row($product);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Engine unreachable or a query error must not 500 the typeahead: degrade to an empty
            // result set (HTTP 200) so the header dropdown shows its empty state, never a broken call.
            $results = [];
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
            'category' => $this->category($product),
            'brand' => $this->brand($product),
        ];
    }

    /**
     * Primary category title (the product's parent category) for the suggestion sub-line, or '' when
     * the product is not filed under a category.
     */
    private function category(Product $product): string
    {
        try {
            $parent = $product->Parent();
            if ($parent instanceof ProductCategory && $parent->exists()) {
                return (string) $parent->Title;
            }
        } catch (\Throwable $e) {
            // Category is optional display metadata — never let it break a suggestion.
        }

        return '';
    }

    /**
     * Brand title when silvershop/brands is installed and the product has one, else '' (the FK guard
     * keeps this dormant and non-fatal when the brands module is absent).
     */
    private function brand(Product $product): string
    {
        try {
            if ($product->hasField('BrandID') && (int) $product->getField('BrandID') > 0) {
                $brand = $product->Brand();
                if ($brand && $brand->exists()) {
                    return (string) $brand->Title;
                }
            }
        } catch (\Throwable $e) {
            // Brand is optional display metadata — never let it break a suggestion.
        }

        return '';
    }
}
