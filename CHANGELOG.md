# Changelog

## 1.0.2 - 2026-06-08

### Fixed
- Unresolved environment placeholders are now treated as empty. `App::parseEnv('$MEILISEARCH_SEARCH_KEY')` returns the literal `$MEILISEARCH_SEARCH_KEY` string when the env var isn't set; left as-is this was sent as a bogus search key (403 → "Search failed") and made `isConfigured()` report a misconfigured host as ready. The search-only key now correctly falls back to the admin key when its env var is unset.

## 1.0.1 - 2026-06-08

### Fixed
- Console command crashed with a fatal "Access level to … run() must be public" error: the private `run()` helper in `IndexController` collided with Yii's public `Controller::run()`. Renamed to `rebuild()`. Verified end-to-end on Craft 4.17 against a live Meilisearch daemon (health, flush/reindex, search proxy, filtering, highlighting and the save-sync hook).

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
