<?php

namespace arifje\craftmeilisearch\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * Editable in the control panel (Settings → Plugins → Meilisearch) and, like any
 * Craft plugin, overridable from a `config/meilisearch.php` file — the natural
 * home for the connection details so the host URL and (secret) API keys live in
 * `.env` / per-environment config rather than the project-config database.
 *
 * A minimal `config/meilisearch.php`:
 *
 *     return [
 *         'hostUrl'   => getenv('MEILISEARCH_HOST'),
 *         'apiKey'    => getenv('MEILISEARCH_KEY'),
 *         'searchKey' => getenv('MEILISEARCH_SEARCH_KEY'),
 *     ];
 */
class Settings extends Model
{
    /**
     * Base URL of the hosted Meilisearch daemon, e.g.
     * `https://ms-xxxx.meilisearch.io` or `http://127.0.0.1:7700`.
     * Supports Craft's env syntax (`$MEILISEARCH_HOST`).
     */
    public string $hostUrl = '$MEILISEARCH_HOST';

    /**
     * Admin/master API key used for indexing and index administration.
     * Supports env syntax (`$MEILISEARCH_KEY`). Never sent to the browser.
     */
    public string $apiKey = '$MEILISEARCH_KEY';

    /**
     * Optional search-only key. When set it is the key used for query requests
     * (the front-end search proxy), keeping the admin key off the hot path.
     * Falls back to {@see $apiKey} when blank. Supports env syntax.
     */
    public string $searchKey = '$MEILISEARCH_SEARCH_KEY';

    /**
     * Prefix prepended to every index name (`{prefix}{indexName}`). Lets several
     * sites/environments share one Meilisearch host without colliding, e.g.
     * `staging_`.
     */
    public string $indexPrefix = '';

    /**
     * Base name of the index that Craft elements are written to. The real index
     * uid is {@see getIndexName()} (= prefix + this).
     */
    public string $indexName = 'craft';

    /**
     * Element index configuration. One entry per element type / source that
     * should be mirrored into Meilisearch. Keyed by an arbitrary id; each value:
     *
     *   [
     *     'type'    => \craft\elements\Entry::class,  // element class
     *     'criteria'=> ['section' => ['news', 'blog']], // params for the element query
     *     'fields'  => ['summary', 'body'],            // custom field handles to index
     *   ]
     *
     * Title, url, slug, status, siteId and the source key are always included.
     * When empty the plugin indexes all entries with a sensible default field set.
     *
     * @var array<string,array{type?:string,criteria?:array,fields?:string[]}>
     */
    public array $sources = [];

    /**
     * Attributes exposed to Meilisearch as `filterableAttributes` (needed for
     * `filter=` queries and faceting). The source key, section handle, type,
     * status and siteId are always filterable.
     *
     * @var string[]
     */
    public array $filterableAttributes = [];

    /**
     * Attributes exposed as `sortableAttributes`. `postDate` and `title` are
     * included by default.
     *
     * @var string[]
     */
    public array $sortableAttributes = [];

    /**
     * Keep indexes in sync automatically by listening to element save/delete
     * events. Turn off if you prefer to drive indexing purely from the console
     * command / your own jobs.
     */
    public bool $syncOnSave = true;

    /**
     * Run the per-element index/delete on the queue instead of inline during the
     * request. Recommended on busy sites; the trade-off is a short delay before
     * a saved element appears in search results.
     */
    public bool $queueSync = true;

    /** Whether the front-end search proxy (search/query action) is enabled. */
    public bool $searchEndpointEnabled = true;

    public function defineRules(): array
    {
        return [
            [['hostUrl', 'apiKey'], 'required'],
            [['hostUrl', 'apiKey', 'searchKey', 'indexPrefix', 'indexName'], 'string'],
            [['indexName'], 'match', 'pattern' => '/^[A-Za-z0-9_-]+$/',
                'message' => 'Index name may only contain letters, numbers, hyphens and underscores.'],
            [['sources', 'filterableAttributes', 'sortableAttributes'], 'safe'],
            [['syncOnSave', 'queueSync', 'searchEndpointEnabled'], 'boolean'],
        ];
    }

    /** Host URL with any `$ENV_VAR` expanded and a trailing slash trimmed. */
    public function getResolvedHostUrl(): string
    {
        return rtrim((string) App::parseEnv($this->hostUrl), '/');
    }

    /** Admin API key with env syntax expanded. */
    public function getResolvedApiKey(): string
    {
        return (string) App::parseEnv($this->apiKey);
    }

    /** Search key with env syntax expanded, falling back to the admin key. */
    public function getResolvedSearchKey(): string
    {
        $key = (string) App::parseEnv($this->searchKey);
        return $key !== '' ? $key : $this->getResolvedApiKey();
    }

    /** Full index uid actually used on the Meilisearch host. */
    public function getIndexName(): string
    {
        return $this->indexPrefix . $this->indexName;
    }

    /** True once a host URL and admin key resolve to non-empty values. */
    public function isConfigured(): bool
    {
        return $this->getResolvedHostUrl() !== '' && $this->getResolvedApiKey() !== '';
    }
}
