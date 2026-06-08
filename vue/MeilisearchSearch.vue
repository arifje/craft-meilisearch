<script setup>
/**
 * MeilisearchSearch — a drop-in instant-search box for the craft-meilisearch
 * plugin. It talks to the plugin's front-end proxy
 * (`/actions/meilisearch/search/query`), so the Meilisearch host and key stay
 * server-side.
 *
 * Two layouts, chosen with the `autocomplete` prop:
 *   - inline (default): results render as a list beneath the input.
 *   - autocomplete:      results render in a floating dropdown with full keyboard
 *                        navigation (↑/↓ to move, Enter to open, Esc to close)
 *                        and combobox ARIA — i.e. a classic typeahead.
 *
 *   <MeilisearchSearch placeholder="Search…" :limit="10" />          <!-- inline -->
 *   <MeilisearchSearch autocomplete placeholder="Search…" />          <!-- typeahead -->
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
  /**
   * Render results as a floating autocomplete/typeahead dropdown with keyboard
   * navigation instead of an always-visible inline list. Off by default.
   */
  autocomplete: { type: Boolean, default: false },
})

const emit = defineEmits(['results', 'select'])

const query = ref('')
const hits = ref([])
const loading = ref(false)
const error = ref('')
const total = ref(0)

// Autocomplete-only state: whether the dropdown is open and which hit is active.
const open = ref(false)
const activeIndex = ref(-1)
const rootEl = ref(null)
const listId = `ms-list-${Math.random().toString(36).slice(2, 9)}`

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
    activeIndex.value = -1
    if (props.autocomplete) open.value = true
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
    if (props.autocomplete) open.value = false
    return
  }
  timer = setTimeout(() => run(trimmed), props.debounce)
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  if (controller) controller.abort()
  document.removeEventListener('click', onDocClick)
})

// --- autocomplete behaviour -------------------------------------------------

function onDocClick(e) {
  if (rootEl.value && !rootEl.value.contains(e.target)) open.value = false
}
// Registered once; cheap no-op in inline mode since `open` is never set true.
if (typeof document !== 'undefined') {
  document.addEventListener('click', onDocClick)
}

function onFocus() {
  if (props.autocomplete && hits.value.length) open.value = true
}

function onKeydown(e) {
  if (!props.autocomplete) return
  if (e.key === 'Escape') { open.value = false; activeIndex.value = -1; return }
  if (!open.value && (e.key === 'ArrowDown' || e.key === 'ArrowUp') && hits.value.length) {
    open.value = true
  }
  if (!hits.value.length) return

  if (e.key === 'ArrowDown') {
    e.preventDefault()
    activeIndex.value = (activeIndex.value + 1) % hits.value.length
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    activeIndex.value = activeIndex.value <= 0 ? hits.value.length - 1 : activeIndex.value - 1
  } else if (e.key === 'Enter') {
    if (activeIndex.value >= 0) {
      e.preventDefault()
      choose(hits.value[activeIndex.value])
    }
  }
}

/** Activate a hit from the keyboard: navigate to its URL or emit `select`. */
function choose(hit) {
  emit('select', hit)
  open.value = false
  if (hit && hit.url) window.location.assign(hit.url)
}

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
  <div
    ref="rootEl"
    class="ms-search"
    :class="{ 'ms-search--ac': autocomplete }"
  >
    <div
      class="ms-search__field"
      role="combobox"
      :aria-expanded="autocomplete ? (open && hits.length > 0) : undefined"
      :aria-owns="autocomplete ? listId : undefined"
      aria-haspopup="listbox"
    >
      <input
        v-model="query"
        type="search"
        class="ms-search__input"
        :placeholder="placeholder"
        autocomplete="off"
        aria-label="Search"
        :aria-controls="autocomplete ? listId : undefined"
        :aria-activedescendant="autocomplete && activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined"
        @focus="onFocus"
        @keydown="onKeydown"
      />
      <span v-if="loading" class="ms-search__spinner" aria-hidden="true"></span>
    </div>

    <p v-if="error" class="ms-search__error">{{ error }}</p>

    <ul
      v-else-if="hits.length && (!autocomplete || open)"
      :id="listId"
      class="ms-search__results"
      role="listbox"
    >
      <li
        v-for="(hit, i) in hits"
        :id="`${listId}-${i}`"
        :key="hit.objectID"
        class="ms-search__hit"
        :class="{ 'is-active': autocomplete && i === activeIndex }"
        role="option"
        :aria-selected="autocomplete && i === activeIndex"
        @mouseenter="autocomplete && (activeIndex = i)"
      >
        <slot name="result" :hit="hit">
          <a :href="hit.url || '#'" class="ms-search__link" @click="onSelect(hit)">
            <span class="ms-search__title" v-html="titleHtml(hit)"></span>
            <span v-if="hit.sectionHandle" class="ms-search__tag">{{ hit.sectionHandle }}</span>
          </a>
        </slot>
      </li>
    </ul>

    <p
      v-else-if="query.trim().length >= minLength && !loading && (!autocomplete || open)"
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
.ms-search__results { list-style: none; margin: 8px 0 0; padding: 0; border: 1px solid #e4e7eb; border-radius: 8px; overflow: hidden; background: #fff; }
.ms-search__hit + .ms-search__hit { border-top: 1px solid #f0f2f5; }
.ms-search__hit.is-active, .ms-search__hit.is-active .ms-search__link { background: #eef2ff; }
.ms-search__link { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; text-decoration: none; color: #1f2933; }
.ms-search__link:hover { background: #f5f7fa; }
.ms-search__title :deep(em) { background: #fff3bf; font-style: normal; }
.ms-search__tag { font-size: 12px; color: #627d98; background: #f0f4f8; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.ms-search__empty, .ms-search__error { margin: 8px 2px 0; color: #627d98; font-size: 14px; }
.ms-search__error { color: #cf1124; }

/* In autocomplete mode the results float over the page instead of pushing it. */
.ms-search--ac .ms-search__results {
  position: absolute;
  left: 0; right: 0;
  z-index: 50;
  margin-top: 4px;
  box-shadow: 0 8px 24px rgba(15, 23, 42, .12);
}
</style>
