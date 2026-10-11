# SilverShop Search — Meilisearch

A [Meilisearch](https://www.meilisearch.com/) engine adapter for
[`silvershop/search`](https://github.com/silvershop/silvershop-search): typo-tolerant, relevance-ranked product
search, as a drop-in replacement for the built-in database backend.

## Requirements

- `silvershop/search` `^1` (the search framework)
- A reachable **Meilisearch** server (self-hosted — a single binary; see their docs)

## Installation

```bash
composer require silvershop/search-meilisearch
```

Point it at your Meilisearch and make it the active backend:

```bash
# .env
SS_MEILISEARCH_HOST="http://127.0.0.1:7700"
SS_MEILISEARCH_KEY="your-master-or-api-key"
```

```yaml
# app/_config/search.yml
SilverShop\Search\Backend\BackendRegistry:
  active_backend: 'meilisearch'
```

Then build the index:

```bash
sake tasks:meilisearch-reindex
```

Run `dev/build?flush=1` first so the config + extension load.

## How it works

- Products are indexed on **publish** and removed on **unpublish / delete** (`ProductSyncExtension`), and the
  **`meilisearch-reindex`** task does a full (re)build plus pushes the index settings.
- A search runs against Meilisearch and returns the matching products **in Meili's relevance order**; the SearchPage
  controller paginates them as usual — no change to `silvershop/search`.
- Indexed fields: `Title`, `InternalItemID` (SKU), `Model`, `Content` (searchable, weighted in that order) and
  `BasePrice` / `CreatedTimestamp` / `Title` (sortable). You get typo-tolerance, relevance ranking and instant-search
  speed out of the box — the gaps the DB backend has.
- A search-engine outage never blocks publishing (sync is guarded); run the reindex task to repair any drift.

## Facets

All registered facets (category / price / brand / rating / colour / attributes / stock) work unchanged with the
Meilisearch backend: Meili does the typo-tolerant text match + ranking, then `silvershop/search`'s facet framework
applies the selected filters and counts options over the matched products. No per-facet configuration needed.

## Roadmap

- Native Meilisearch `facetDistribution` (a performance optimisation for very large catalogs).
- Synonyms (CMS-editable), searchable-attribute weighting tuning, optional instant-search JSON endpoint.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
