/**
 * No-build version of the MeilisearchSearch component.
 *
 * For projects without a bundler. Drop Vue 3's global build and this file on the
 * page, then use the <meilisearch-search> custom element:
 *
 *   <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
 *   <script src="/path/to/meilisearch-search.global.js"></script>
 *
 *   <meilisearch-search placeholder="Search…" limit="10"></meilisearch-search>
 *   <meilisearch-search autocomplete placeholder="Search…"></meilisearch-search>
 *
 * Attributes map to the same props as the SFC: action, placeholder, limit,
 * filter, debounce, min-length, autocomplete (boolean). The element dispatches
 * `ms:select` and `ms:results` DOM CustomEvents.
 */
(function () {
  if (typeof Vue === 'undefined') {
    console.error('[meilisearch-search] Vue 3 global build must be loaded first.')
    return
  }

  const { defineCustomElement } = Vue

  const MeilisearchSearch = defineCustomElement({
    props: {
      action: { type: String, default: '/actions/meilisearch/search/query' },
      placeholder: { type: String, default: 'Search…' },
      limit: { type: Number, default: 10 },
      filter: { type: String, default: '' },
      debounce: { type: Number, default: 200 },
      minLength: { type: Number, default: 1 },
      // Floating typeahead dropdown with keyboard nav, instead of an inline list.
      autocomplete: { type: Boolean, default: false },
    },
    data() {
      return {
        query: '', hits: [], loading: false, error: '', total: 0,
        open: false, activeIndex: -1,
        _timer: null, _controller: null, _blurTimer: null,
      }
    },
    watch: {
      query(q) {
        clearTimeout(this._timer)
        const trimmed = q.trim()
        if (trimmed.length < this.minLength) {
          this.hits = []; this.total = 0; this.loading = false; this.open = false
          return
        }
        this._timer = setTimeout(() => this.run(trimmed), this.debounce)
      },
    },
    methods: {
      async run(q) {
        if (this._controller) this._controller.abort()
        this._controller = new AbortController()
        this.loading = true; this.error = ''

        const params = new URLSearchParams({ q, limit: String(this.limit) })
        if (this.filter) params.set('filter', this.filter)

        try {
          const res = await fetch(this.action + '?' + params.toString(), {
            headers: { Accept: 'application/json' },
            signal: this._controller.signal,
          })
          if (!res.ok) throw new Error('Search failed (' + res.status + ')')
          const data = await res.json()
          this.hits = data.hits || []
          this.total = data.estimatedTotalHits != null ? data.estimatedTotalHits : this.hits.length
          this.activeIndex = -1
          if (this.autocomplete) this.open = true
          this.dispatchEvent(new CustomEvent('ms:results', { detail: data, bubbles: true, composed: true }))
        } catch (e) {
          if (e.name !== 'AbortError') {
            this.error = e.message || 'Search failed'
            this.hits = []; this.total = 0
          }
        } finally {
          this.loading = false
        }
      },
      onFocus() {
        clearTimeout(this._blurTimer)
        if (this.autocomplete && this.hits.length) this.open = true
      },
      onBlur() {
        // Delay so a click on a result (which blurs the input first) still lands.
        if (!this.autocomplete) return
        this._blurTimer = setTimeout(() => { this.open = false }, 160)
      },
      onKeydown(e) {
        if (!this.autocomplete) return
        if (e.key === 'Escape') { this.open = false; this.activeIndex = -1; return }
        if (!this.open && (e.key === 'ArrowDown' || e.key === 'ArrowUp') && this.hits.length) this.open = true
        if (!this.hits.length) return
        if (e.key === 'ArrowDown') {
          e.preventDefault()
          this.activeIndex = (this.activeIndex + 1) % this.hits.length
        } else if (e.key === 'ArrowUp') {
          e.preventDefault()
          this.activeIndex = this.activeIndex <= 0 ? this.hits.length - 1 : this.activeIndex - 1
        } else if (e.key === 'Enter' && this.activeIndex >= 0) {
          e.preventDefault()
          this.choose(this.hits[this.activeIndex])
        }
      },
      choose(hit) {
        this.dispatchEvent(new CustomEvent('ms:select', { detail: hit, bubbles: true, composed: true }))
        this.open = false
        if (hit && hit.url) window.location.assign(hit.url)
      },
      titleHtml(hit) {
        return (hit._formatted && hit._formatted.title) || this.escapeHtml(hit.title || '')
      },
      escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
          '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c])
      },
      onSelect(hit) {
        this.dispatchEvent(new CustomEvent('ms:select', { detail: hit, bubbles: true, composed: true }))
      },
    },
    beforeUnmount() {
      clearTimeout(this._timer)
      clearTimeout(this._blurTimer)
      if (this._controller) this._controller.abort()
    },
    template: `
      <div class="ms-search" :class="{ 'ms-search--ac': autocomplete }">
        <div class="ms-search__field" role="combobox" aria-haspopup="listbox"
             :aria-expanded="autocomplete ? (open && hits.length > 0) : null">
          <input v-model="query" type="search" class="ms-search__input"
                 :placeholder="placeholder" autocomplete="off" aria-label="Search"
                 :aria-activedescendant="autocomplete && activeIndex >= 0 ? 'ms-opt-' + activeIndex : null"
                 @focus="onFocus" @blur="onBlur" @keydown="onKeydown" />
          <span v-if="loading" class="ms-search__spinner" aria-hidden="true"></span>
        </div>
        <p v-if="error" class="ms-search__error">{{ error }}</p>
        <ul v-else-if="hits.length && (!autocomplete || open)" class="ms-search__results" role="listbox">
          <li v-for="(hit, i) in hits" :id="'ms-opt-' + i" :key="hit.objectID"
              class="ms-search__hit" :class="{ 'is-active': autocomplete && i === activeIndex }"
              role="option" :aria-selected="autocomplete && i === activeIndex"
              @mouseenter="autocomplete ? activeIndex = i : null">
            <a :href="hit.url || '#'" class="ms-search__link" @click="onSelect(hit)">
              <span class="ms-search__title" v-html="titleHtml(hit)"></span>
              <span v-if="hit.sectionHandle" class="ms-search__tag">{{ hit.sectionHandle }}</span>
            </a>
          </li>
        </ul>
        <p v-else-if="query.trim().length >= minLength && !loading && (!autocomplete || open)" class="ms-search__empty">
          No results for &ldquo;{{ query }}&rdquo;.
        </p>
      </div>
    `,
    styles: [`
      .ms-search { position: relative; max-width: 640px; font-family: inherit; }
      .ms-search__field { position: relative; }
      .ms-search__input { width: 100%; box-sizing: border-box; padding: 10px 36px 10px 14px; font-size: 16px; border: 1px solid #cdd5df; border-radius: 8px; outline: none; }
      .ms-search__input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
      .ms-search__spinner { position: absolute; right: 12px; top: 50%; width: 16px; height: 16px; margin-top: -8px; border: 2px solid #cdd5df; border-top-color: #4f46e5; border-radius: 50%; animation: ms-spin .6s linear infinite; }
      @keyframes ms-spin { to { transform: rotate(360deg); } }
      .ms-search__results { list-style: none; margin: 8px 0 0; padding: 0; border: 1px solid #e4e7eb; border-radius: 8px; overflow: hidden; background: #fff; }
      .ms-search__hit + .ms-search__hit { border-top: 1px solid #f0f2f5; }
      .ms-search__hit.is-active, .ms-search__hit.is-active .ms-search__link { background: #eef2ff; }
      .ms-search__link { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; text-decoration: none; color: #1f2933; }
      .ms-search__link:hover { background: #f5f7fa; }
      .ms-search__title em { background: #fff3bf; font-style: normal; }
      .ms-search__tag { font-size: 12px; color: #627d98; background: #f0f4f8; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
      .ms-search__empty, .ms-search__error { margin: 8px 2px 0; color: #627d98; font-size: 14px; }
      .ms-search__error { color: #cf1124; }
      .ms-search--ac .ms-search__results { position: absolute; left: 0; right: 0; z-index: 50; margin-top: 4px; box-shadow: 0 8px 24px rgba(15,23,42,.12); }
    `],
  })

  if (!customElements.get('meilisearch-search')) {
    customElements.define('meilisearch-search', MeilisearchSearch)
  }
})()
