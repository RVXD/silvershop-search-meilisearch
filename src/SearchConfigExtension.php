<?php

declare(strict_types=1);

namespace SilverShop\Search\Meilisearch;

use SilverShop\Search\Backend\BackendRegistry;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;

/**
 * CMS-editable search synonyms on SiteConfig. Saving Settings re-pushes the Meilisearch index settings (synonyms +
 * searchable attributes) so merchants can tune synonyms without a reindex. Only acts when Meilisearch is the active,
 * configured backend, and never lets a search hiccup block saving.
 *
 * @extends Extension<\SilverStripe\SiteConfig\SiteConfig>
 */
class SearchConfigExtension extends Extension
{
    private static array $db = [
        'SearchSynonyms' => 'Text',
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->addFieldToTab('Root.Shop', TextareaField::create(
            'SearchSynonyms',
            _t(self::class . '.SearchSynonyms', 'Search synonyms')
        )->setDescription(_t(
            self::class . '.SearchSynonymsDesc',
            'One group per line, comma-separated — every term in a group matches the others '
            . '(e.g. "tv, television, telly"). Used by the Meilisearch search backend.'
        )));
    }

    protected function onAfterWrite(): void
    {
        $backend = (new BackendRegistry())->getActive();
        if ($backend instanceof MeilisearchBackend && $backend->isConfigured()) {
            try {
                $backend->pushSettings();
            } catch (\Throwable) {
                // A search-engine hiccup must not block saving Settings; the reindex task can repair it.
            }
        }
    }
}
