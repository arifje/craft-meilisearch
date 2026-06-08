# Meilisearch for Craft CMS

Index and search your Craft elements with a hosted [Meilisearch](https://www.meilisearch.com/)
instance. The plugin keeps your Meilisearch index in sync as content changes,
exposes a safe front-end search endpoint, and ships a drop-in **Vue 3 search
component**.

- **Craft 4 and Craft 5** (PHP 8.0.2+) from a single codebase.
- **No extra PHP dependencies** — talks to Meilisearch over Craft's bundled Guzzle.
- **Automatic sync** on element save / delete / restore (inline or queued).
- **Server-side search proxy** so your Meilisearch host and key never reach the browser.
- **Console commands** for full reindexing.
- **Vue component** included — as an SFC for build setups and a no-build custom element.

## Requirements

- Craft CMS 4.0+ or 5.0+
- PHP 8.0.2+
- A reachable Meilisearch host (e.g. Meilisearch Cloud or self-hosted)

## Installation

```bash
composer require arifje/craft-meilisearch
php craft plugin/install meilisearch
```

## Configuration

Set the connection details either in **Settings → Plugins → Meilisearch** in the
control panel, or — recommended — in a `config/meilisearch.php` file. Copy
[`config.example.php`](config.example.php) as a starting point and keep the
secrets in `.env`:

```dotenv
MEILISEARCH_HOST="https://ms-xxxx.meilisearch.io"
MEILISEARCH_KEY="your-admin-key"
MEILISEARCH_SEARCH_KEY="your-search-only-key"
```

The settings screen shows a live connection check so you can confirm the host
and key before indexing.

### Choosing what to index

With no `sources` configured the plugin indexes every entry. To narrow it down
(or add other element types, custom fields, Commerce products, etc.) define
`sources` in `config/meilisearch.php`:

```php
use craft\elements\Entry;

return [
    'sources' => [
        'news' => [
            'type'     => Entry::class,
            'criteria' => ['section' => ['news', 'blog']],
            'fields'   => ['summary', 'body'],
        ],
    ],
];
```

Every document always includes `objectID`, `elementId`, `siteId`, `source`,
`type`, `sectionHandle`, `status`, `title`, `slug`, `url` and `postDate`, plus
any custom field handles you list under `fields`.

## Indexing

Content is synced automatically when you save or delete elements. To (re)build
the index from scratch — for example after first install or a config change:

```bash
php craft meilisearch/index/health     # check the connection
php craft meilisearch/index/reindex    # upsert every configured source
php craft meilisearch/index/flush      # drop the index and rebuild
```

Indexing is asynchronous on the Meilisearch side, so allow a moment after a
reindex before results appear.

## Searching

The plugin proxies searches through Craft so your keys stay private:

```
GET /actions/meilisearch/search/query?q=hello&limit=10
GET /actions/meilisearch/search/query?q=hello&filter=sectionHandle = "news"
```

Returns Meilisearch's native JSON response (`hits`, `estimatedTotalHits`,
`processingTimeMs`, …). Supported query params: `q`, `limit` (max 100), `offset`,
`filter`, `sort`, `facets`, `attributesToRetrieve`, `attributesToHighlight`.

Use it directly from your own JS, or use the bundled Vue component.

## Vue component

A ready-made instant-search box lives in [`vue/`](vue/). It calls the search
proxy above, debounces input, highlights matches and renders results.

```vue
<script setup>
import MeilisearchSearch from './vue/MeilisearchSearch.vue'
</script>

<template>
  <MeilisearchSearch placeholder="Search the site…" :limit="10" />
</template>
```

No build step? Load Vue's global build and the bundled custom element:

```html
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<script src="/path/to/meilisearch-search.global.js"></script>

<meilisearch-search placeholder="Search…" limit="10"></meilisearch-search>
```

See [`vue/README.md`](vue/README.md) for props, events and slots.

## How it works

| Piece | Responsibility |
|-------|----------------|
| `services/Meilisearch` | Thin HTTP client to the daemon (documents, search, index settings) |
| `services/IndexService` | Turns elements into flat documents and keeps the index in sync |
| `controllers/SearchController` | Public JSON search proxy using the search-only key |
| `jobs/IndexElementJob` | Per-element (re)index / delete on the queue |
| `console/IndexController` | `health` / `reindex` / `flush` commands |

## License

MIT
