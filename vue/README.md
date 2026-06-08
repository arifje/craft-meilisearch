# MeilisearchSearch — Vue 3 component

A drop-in instant-search box for the [craft-meilisearch](../) plugin. It queries
the plugin's front-end proxy (`/actions/meilisearch/search/query`), so your
Meilisearch host and API key never reach the browser.

Two ways to use it depending on whether you have a build step.

## With a build step (Vite, Nuxt, etc.)

Copy `MeilisearchSearch.vue` into your project (or install this folder as a local
package) and import it:

```vue
<script setup>
import MeilisearchSearch from '@arifje/craft-meilisearch-vue'
</script>

<template>
  <MeilisearchSearch
    placeholder="Search the site…"
    :limit="10"
    filter='sectionHandle = "news"'
    @select="hit => console.log('clicked', hit)"
  />
</template>
```

### Props

| Prop          | Type             | Default                                   | Notes |
|---------------|------------------|-------------------------------------------|-------|
| `action`      | String           | `/actions/meilisearch/search/query`       | Plugin search endpoint |
| `placeholder` | String           | `Search…`                                 | Input placeholder |
| `limit`       | Number           | `10`                                      | Max hits to request |
| `filter`      | String \| Array  | `''`                                      | Meilisearch filter, e.g. `sectionHandle = "news"`; array entries are AND-joined |
| `debounce`    | Number           | `200`                                     | Debounce delay (ms) |
| `minLength`   | Number           | `1`                                       | Min query length before searching |

### Events

- `results` — emitted with the full Meilisearch response on every successful query.
- `select` — emitted with the clicked hit.

### Slots

- `result` — scoped slot (`{ hit }`) to render each hit your own way.

```vue
<MeilisearchSearch>
  <template #result="{ hit }">
    <a :href="hit.url">{{ hit.title }} — {{ hit.sectionHandle }}</a>
  </template>
</MeilisearchSearch>
```

## Without a build step (custom element)

Load Vue 3's global build and the bundled `meilisearch-search.global.js`, then
use the `<meilisearch-search>` custom element anywhere — including inside a Twig
template:

```html
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<script src="/assets/meilisearch-search.global.js"></script>

<meilisearch-search placeholder="Search…" limit="10"></meilisearch-search>
```

Attributes map to the same props (`action`, `placeholder`, `limit`, `filter`,
`debounce`, `min-length`). The element dispatches `ms:select` and `ms:results`
DOM `CustomEvent`s that bubble out of the shadow root:

```html
<script>
  document.querySelector('meilisearch-search')
    .addEventListener('ms:select', e => console.log('clicked', e.detail))
</script>
```

## Document shape

Each hit is a flat document produced by the plugin's indexer:

```json
{
  "objectID": "1_42",
  "elementId": 42,
  "siteId": 1,
  "source": "news",
  "sectionHandle": "news",
  "status": "live",
  "title": "Hello world",
  "slug": "hello-world",
  "url": "https://example.com/news/hello-world",
  "postDate": 1733654400
}
```
