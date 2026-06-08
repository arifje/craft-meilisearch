# Changelog

## 1.0.0 - 2026-06-08

### Added
- Initial release.
- Connects to a hosted Meilisearch daemon over Craft's bundled Guzzle (no extra dependencies).
- Automatic index sync on element save/delete/restore, inline or on the queue.
- Configurable sources (element types, query criteria, extra field handles) via `config/meilisearch.php`.
- Console commands: `meilisearch/index/health`, `meilisearch/index/reindex`, `meilisearch/index/flush`.
- Front-end JSON search proxy (`meilisearch/search/query`) keeping the host/key server-side.
- Control-panel settings screen with a live connection status check.
- Bundled Vue 3 search component (`MeilisearchSearch.vue`) plus a no-build `<meilisearch-search>` custom element.
- Compatible with Craft 4 and Craft 5 on PHP 8.0.2+.
