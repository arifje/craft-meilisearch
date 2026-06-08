<?php
/**
 * Example config for the craft-meilisearch plugin.
 *
 * Copy this file to `config/meilisearch.php` in your Craft project. Values here
 * override the control-panel settings, so it's the right place for connection
 * details (keep the secrets in `.env`) and for the source configuration, which
 * isn't editable from the CP.
 *
 * Like all Craft config files it supports multi-environment arrays.
 */

use craft\elements\Entry;

return [
    // --- Connection (best kept in .env) ---
    'hostUrl'   => '$MEILISEARCH_HOST',        // e.g. https://ms-xxxx.meilisearch.io
    'apiKey'    => '$MEILISEARCH_KEY',          // admin/master key
    'searchKey' => '$MEILISEARCH_SEARCH_KEY',   // optional search-only key

    // --- Index ---
    'indexName'   => 'craft',
    'indexPrefix' => '',   // e.g. 'staging_' to share a host between environments

    // --- What to index ---
    // Omit `sources` entirely to index every entry with the default fields.
    'sources' => [
        'news' => [
            'type'     => Entry::class,
            'criteria' => ['section' => ['news', 'blog']],
            'fields'   => ['summary', 'body'],   // extra custom field handles to index
            // Optional: which element statuses count as indexable for this source.
            // Defaults to `activeStatuses` below.
            // 'statuses' => ['live'],
        ],
        // 'products' => [
        //     'type'     => \craft\commerce\elements\Product::class,
        //     'criteria' => [],
        //     'fields'   => ['description'],
        // ],
    ],

    // Element statuses treated as "show in search", used as the default for every
    // source (entries are 'live', most elements 'enabled', users 'active').
    'activeStatuses' => ['live', 'enabled', 'active'],

    // Attributes you want to filter or sort on (beyond the always-on ones:
    // source, type, sectionHandle, status, siteId, elementId / postDate, title).
    'filterableAttributes' => [],
    'sortableAttributes'   => [],

    // Any other Meilisearch index settings, applied verbatim on (re)index.
    // See https://www.meilisearch.com/docs/reference/api/settings
    'indexSettings' => [
        // 'searchableAttributes' => ['title', 'summary', 'body'],
        // 'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
        // 'stopWords'    => ['the', 'a', 'an'],
        // 'synonyms'     => ['nyc' => ['new york']],
        // 'typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
    ],

    // --- Behaviour ---
    'syncOnSave'            => true,
    'queueSync'            => true,
    'searchEndpointEnabled' => true,
];
