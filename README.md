# SilverShop Search — Meilisearch

A [Meilisearch](https://www.meilisearch.com/) engine adapter for
[`silvershop/search`](https://github.com/silvershop/silvershop-search): typo-tolerant, relevance-ranked product
search, as a drop-in replacement for the built-in database backend.

## Requirements

- `silvershop/search` `^1` (the search framework)
- A reachable **Meilisearch** server (self-hosted — a single binary)

## Running Meilisearch

Meilisearch is a single binary — download it ([docs](https://www.meilisearch.com/docs/learn/getting_started/installation)):

```bash
curl -L https://install.meilisearch.com | sh   # downloads ./meilisearch
```

For anything beyond a quick local test, run it under systemd. A system service (`/etc/systemd/system/meilisearch.service`):

```ini
[Unit]
Description=Meilisearch
After=network.target

[Service]
Type=simple
User=meilisearch
Environment=MEILI_MASTER_KEY=change-me-to-a-long-random-key
Environment=MEILI_ENV=production
Environment=MEILI_DB_PATH=/var/lib/meilisearch/data
Environment=MEILI_HTTP_ADDR=127.0.0.1:7700
ExecStart=/usr/local/bin/meilisearch
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now meilisearch
```

No root? A **per-user** service works too — put the unit in `~/.config/systemd/user/meilisearch.service` (use `%h/...`
paths and `ExecStart=%h/meilisearch`), then:

```bash
systemctl --user enable --now meilisearch
sudo loginctl enable-linger "$USER"   # one-time: start it at boot without logging in
```

Keep Meilisearch bound to localhost (behind your app) and use a long random `MEILI_MASTER_KEY` in production.

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

## Relevance & synonyms

**Field weighting** — free-text matches rank by field importance: `Title`, then SKU (`InternalItemID`), `Model`,
`Content`. Reorder via config:

```yaml
SilverShop\Search\Meilisearch\MeilisearchBackend:
  searchable_attributes: [Title, InternalItemID, Model, Content]
```

**Synonyms** — edit them in the CMS at **Settings → Shop → Search synonyms**: one group per line, comma-separated
(e.g. `tv, television, telly`). Every term in a group matches the others. Saving pushes them to Meilisearch
immediately — no reindex needed.

**Typo tolerance** — the minimum word length before a typo is tolerated. Meilisearch's own defaults (`oneTypo` 5,
`twoTypos` 9) give short terms zero tolerance, so a 4-letter query like `iPad` would never reach `iPod`. The module
lowers the one-typo threshold to **4** so short-word typos match. Tune per key via config (run the reindex task, or
re-save synonyms, to push it):

```yaml
SilverShop\Search\Meilisearch\MeilisearchBackend:
  min_word_size_for_typos:
    oneTypo: 4
    twoTypos: 7
```

## Instant search (autocomplete)

A JSON endpoint for typeahead lives at `/<your search page>/instant?q=…` (e.g. `/search/instant?q=tshi`). It returns
the top matches fast, for a theme to render a suggestions dropdown:

```json
{"query":"tshi","results":[{"title":"T-Shirt","link":"/shop/apparel/t-shirt/","price":"$25.00","image":"/…"}]}
```

Limit via config:

```yaml
SilverShop\Search\Meilisearch\MeilisearchBackend:
  instant_limit: 8
```

## Roadmap

- Native Meilisearch `facetDistribution` (a performance optimisation for very large catalogs).

## License

BSD-3-Clause. See [LICENSE](LICENSE).
