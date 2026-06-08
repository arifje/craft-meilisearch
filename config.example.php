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
        ],
        // 'products' => [
        //     'type'     => \craft\commerce\elements\Product::class,
        //     'criteria' => [],
        //     'fields'   => ['description'],
        // ],
    ],

    // Attributes you want to filter or sort on (beyond the always-on ones:
    // source, type, sectionHandle, status, siteId, elementId / postDate, title).
    'filterableAttributes' => [],
    'sortableAttributes'   => [],

    // --- Behaviour ---
    'syncOnSave'            => true,
    'queueSync'            => true,
    'searchEndpointEnabled' => true,
];
