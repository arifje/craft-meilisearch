<?php

namespace arifje\craftmeilisearch\services;

use arifje\craftmeilisearch\models\Settings;
use arifje\craftmeilisearch\Plugin;
use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use yii\base\Component;

/**
 * Thin client for the Meilisearch HTTP API.
 *
 * Deliberately dependency-free: it talks to the daemon over Craft's bundled
 * Guzzle rather than pulling in the official SDK, so the plugin installs cleanly
 * on both Craft 4 and Craft 5 with no version juggling. Only the handful of
 * endpoints the plugin actually needs are wrapped — documents, search, index
 * settings and tasks.
 *
 * @see https://www.meilisearch.com/docs/reference/api/overview
 */
class Meilisearch extends Component
{
    private ?Client $admin = null;
    private ?Client $search = null;

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    /** Guzzle client bound to the host with the given bearer key. */
    private function client(string $key): Client
    {
        $settings = $this->settings();
        return Craft::createGuzzleClient([
            'base_uri' => $settings->getResolvedHostUrl() . '/',
            'headers'  => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'http_errors' => false,
            // Meilisearch acknowledges writes immediately (async task) and search
            // is fast, so a short timeout is safe and keeps a misconfigured host
            // from hanging the settings page / a content save.
            'timeout'     => 10,
        ]);
    }

    private function adminClient(): Client
    {
        return $this->admin ??= $this->client($this->settings()->getResolvedApiKey());
    }

    private function searchClient(): Client
    {
        return $this->search ??= $this->client($this->settings()->getResolvedSearchKey());
    }

    /**
     * Issue a request and decode the JSON body. Throws {@see MeilisearchException}
     * on transport errors or any non-2xx response, surfacing Meilisearch's own
     * error message/code where present.
     *
     * @throws MeilisearchException
     */
    private function request(Client $client, string $method, string $uri, ?array $json = null): array
    {
        try {
            $response = $client->request($method, ltrim($uri, '/'), $json !== null ? ['json' => $json] : []);
        } catch (GuzzleException $e) {
            throw new MeilisearchException('Could not reach Meilisearch: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = $body !== '' ? json_decode($body, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        if ($status < 200 || $status >= 300) {
            $message = $data['message'] ?? ('Meilisearch returned HTTP ' . $status);
            throw new MeilisearchException($message, $status, null, $data['code'] ?? null);
        }

        return $data;
    }

    // -- Health / connection -------------------------------------------------

    /**
     * Ping the daemon. Returns `['ok' => bool, 'message' => string]` so callers
     * (settings screen, console) can show a friendly status without catching.
     */
    public function health(): array
    {
        if (!$this->settings()->isConfigured()) {
            return ['ok' => false, 'message' => 'Host URL and API key are not configured.'];
        }
        try {
            $data = $this->request($this->adminClient(), 'GET', 'health');
            return ['ok' => ($data['status'] ?? '') === 'available', 'message' => 'Connected.'];
        } catch (MeilisearchException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // -- Index management ----------------------------------------------------

    /**
     * Create the index if it does not exist and push its settings. Idempotent —
     * safe to call on every boot or reindex.
     *
     * @param array<string,mixed> $settings A Meilisearch "update settings"
     *        payload (filterableAttributes, sortableAttributes, rankingRules,
     *        synonyms, …).
     * @throws MeilisearchException
     */
    public function ensureIndex(string $uid, string $primaryKey, array $settings): void
    {
        // Creating an index that already exists returns a benign error code; we
        // treat "index_already_exists" as success.
        try {
            $this->request($this->adminClient(), 'POST', 'indexes', [
                'uid'        => $uid,
                'primaryKey' => $primaryKey,
            ]);
        } catch (MeilisearchException $e) {
            if ($e->meiliCode !== 'index_already_exists') {
                throw $e;
            }
        }

        $this->request($this->adminClient(), 'PATCH', "indexes/{$uid}/settings", $settings);
    }

    /** Index statistics (numberOfDocuments, isIndexing, …). @throws MeilisearchException */
    public function stats(string $uid): array
    {
        return $this->request($this->adminClient(), 'GET', "indexes/{$uid}/stats");
    }

    /** Delete the whole index. Ignores a missing index. @throws MeilisearchException */
    public function deleteIndex(string $uid): void
    {
        try {
            $this->request($this->adminClient(), 'DELETE', "indexes/{$uid}");
        } catch (MeilisearchException $e) {
            if ($e->statusCode !== 404) {
                throw $e;
            }
        }
    }

    // -- Documents -----------------------------------------------------------

    /**
     * Add or replace documents. Returns the async task payload (`taskUid`, …).
     *
     * @param array<int,array<string,mixed>> $documents
     * @throws MeilisearchException
     */
    public function addDocuments(string $uid, array $documents, string $primaryKey): array
    {
        return $this->request(
            $this->adminClient(),
            'PUT',
            "indexes/{$uid}/documents?primaryKey={$primaryKey}",
            array_values($documents)
        );
    }

    /** Delete one document by primary key. @throws MeilisearchException */
    public function deleteDocument(string $uid, string $id): array
    {
        return $this->request($this->adminClient(), 'DELETE', "indexes/{$uid}/documents/{$id}");
    }

    /**
     * Delete documents matching a filter expression, e.g. `siteId = 2`.
     * @throws MeilisearchException
     */
    public function deleteDocumentsByFilter(string $uid, string $filter): array
    {
        return $this->request($this->adminClient(), 'POST', "indexes/{$uid}/documents/delete", [
            'filter' => $filter,
        ]);
    }

    // -- Search --------------------------------------------------------------

    /**
     * Run a search against the index using the search-only key.
     *
     * @param array<string,mixed> $params Extra Meilisearch search params
     *                                    (filter, limit, offset, sort, facets…).
     * @throws MeilisearchException
     */
    public function search(string $uid, string $query, array $params = []): array
    {
        $payload = array_merge(['q' => $query], $params);
        return $this->request($this->searchClient(), 'POST', "indexes/{$uid}/search", $payload);
    }
}
