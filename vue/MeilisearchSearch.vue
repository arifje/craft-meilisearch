<script setup>
/**
 * MeilisearchSearch — a drop-in instant-search box for the craft-meilisearch
 * plugin. It talks to the plugin's front-end proxy
 * (`/actions/meilisearch/search/query`), so the Meilisearch host and key stay
 * server-side.
 *
 * Usage:
 *   <MeilisearchSearch placeholder="Search the site…" :limit="10" />
 *
 * The component is intentionally unopinionated about styling — it ships with
 * minimal scoped CSS you can override, and exposes a `#result` slot so you can
 * render hits however you like.
 */
import { ref, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
  /** Action endpoint of the plugin's search proxy. */
  action: { type: String, default: '/actions/meilisearch/search/query' },
  /** Placeholder text for the input. */
  placeholder: { type: String, default: 'Search…' },
  /** Max number of hits to request. */
  limit: { type: Number, default: 10 },
  /** Optional Meilisearch filter expression, e.g. `sectionHandle = "news"`. */
  filter: { type: [String, Array], default: '' },
  /** Debounce delay in milliseconds. */
  debounce: { type: Number, default: 200 },
  /** Minimum query length before a request is made. */
  minLength: { type: Number, default: 1 },
})

const emit = defineEmits(['results', 'select'])

const query = ref('')
const hits = ref([])
const loading = ref(false)
const error = ref('')
const total = ref(0)

let timer = null
let controller = null

async function run(q) {
  // Cancel any in-flight request so results never arrive out of order.
  if (controller) controller.abort()
  controller = new AbortController()
  loading.value = true
  error.value = ''

  const params = new URLSearchParams({ q, limit: String(props.limit) })
  if (props.filter) {
    const f = Array.isArray(props.filter) ? props.filter.join(' AND ') : props.filter
    params.set('filter', f)
  }

  try {
    const res = await fetch(`${props.action}?${params.toString()}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
    if (!res.ok) throw new Error(`Search failed (${res.status})`)
    const data = await res.json()
    hits.value = data.hits || []
    total.value = data.estimatedTotalHits ?? hits.value.length
    emit('results', data)
  } catch (e) {
    if (e.name !== 'AbortError') {
      error.value = e.message || 'Search failed'
      hits.value = []
      total.value = 0
    }
  } finally {
    loading.value = false
  }
}

watch(query, (q) => {
  clearTimeout(timer)
  const trimmed = q.trim()
  if (trimmed.length < props.minLength) {
    hits.value = []
    total.value = 0
    loading.value = false
    return
  }
  timer = setTimeout(() => run(trimmed), props.debounce)
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  if (controller) controller.abort()
})

/** Meilisearch returns highlighted text in `_formatted`; prefer it when present. */
function titleHtml(hit) {
  return (hit._formatted && hit._formatted.title) || escapeHtml(hit.title || '')
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[c])
}

function onSelect(hit) {
  emit('select', hit)
}
</script>

<template>
  <div class="ms-search">
    <div class="ms-search__field">
      <input
        v-model="query"
        type="search"
        class="ms-search__input"
        :placeholder="placeholder"
        autocomplete="off"
        aria-label="Search"
      />
      <span v-if="loading" class="ms-search__spinner" aria-hidden="true"></span>
    </div>

    <p v-if="error" class="ms-search__error">{{ error }}</p>

    <ul v-else-if="hits.length" class="ms-search__results">
      <li v-for="hit in hits" :key="hit.objectID" class="ms-search__hit">
        <slot name="result" :hit="hit">
          <a :href="hit.url || '#'" class="ms-search__link" @click="onSelect(hit)">
            <span class="ms-search__title" v-html="titleHtml(hit)"></span>
            <span v-if="hit.sectionHandle" class="ms-search__tag">{{ hit.sectionHandle }}</span>
          </a>
        </slot>
      </li>
    </ul>

    <p
      v-else-if="query.trim().length >= minLength && !loading"
      class="ms-search__empty"
    >
      No results for “{{ query }}”.
    </p>
  </div>
</template>

<style scoped>
.ms-search { position: relative; max-width: 640px; }
.ms-search__field { position: relative; }
.ms-search__input {
  width: 100%;
  box-sizing: border-box;
  padding: 10px 36px 10px 14px;
  font-size: 16px;
  border: 1px solid #cdd5df;
  border-radius: 8px;
  outline: none;
}
.ms-search__input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, .15); }
.ms-search__spinner {
  position: absolute;
  right: 12px; top: 50%;
  width: 16px; height: 16px;
  margin-top: -8px;
  border: 2px solid #cdd5df;
  border-top-color: #4f46e5;
  border-radius: 50%;
  animation: ms-spin .6s linear infinite;
}
@keyframes ms-spin { to { transform: rotate(360deg); } }
.ms-search__results { list-style: none; margin: 8px 0 0; padding: 0; border: 1px solid #e4e7eb; border-radius: 8px; overflow: hidden; }
.ms-search__hit + .ms-search__hit { border-top: 1px solid #f0f2f5; }
.ms-search__link { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; text-decoration: none; color: #1f2933; }
.ms-search__link:hover { background: #f5f7fa; }
.ms-search__title :deep(em) { background: #fff3bf; font-style: normal; }
.ms-search__tag { font-size: 12px; color: #627d98; background: #f0f4f8; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.ms-search__empty, .ms-search__error { margin: 8px 2px 0; color: #627d98; font-size: 14px; }
.ms-search__error { color: #cf1124; }
</style>
