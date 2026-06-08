<?php

namespace arifje\craftmeilisearch\services;

use arifje\craftmeilisearch\models\Settings;
use arifje\craftmeilisearch\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\db\ElementQueryInterface;
use yii\base\Component;

/**
 * Turns Craft elements into Meilisearch documents and keeps the index in sync.
 *
 * The shape of a document is intentionally flat and predictable so the front-end
 * (and the bundled Vue component) can rely on it:
 *
 *   objectID, elementId, siteId, source, type, sectionHandle, status,
 *   title, slug, url, postDate (unix ts) + each configured custom field handle.
 *
 * `objectID` is the Meilisearch primary key — `{siteId}_{elementId}` so the same
 * element in different sites stays distinct in a single shared index.
 */
class IndexService extends Component
{
    public const PRIMARY_KEY = 'objectID';

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    private function client(): Meilisearch
    {
        return Plugin::getInstance()->meilisearch;
    }

    /**
     * The configured sources, falling back to "all entries" when none are set.
     * Each source's `statuses` defaults to the plugin-wide `activeStatuses`.
     *
     * @return array<int,array{type:string,criteria:array,fields:string[],statuses:string[]}>
     */
    public function getSources(): array
    {
        $default = $this->settings()->activeStatuses;
        $sources = $this->settings()->sources;
        if (empty($sources)) {
            return [[
                'type'     => Entry::class,
                'criteria' => [],
                'fields'   => [],
                'statuses' => $default,
            ]];
        }

        $normalized = [];
        foreach ($sources as $source) {
            $normalized[] = [
                'type'     => $source['type'] ?? Entry::class,
                'criteria' => $source['criteria'] ?? [],
                'fields'   => $source['fields'] ?? [],
                'statuses' => $source['statuses'] ?? $default,
            ];
        }
        return $normalized;
    }

    /** Custom field handles to index, gathered across every source. @return string[] */
    private function customFieldHandles(): array
    {
        $handles = [];
        foreach ($this->getSources() as $source) {
            foreach ($source['fields'] as $handle) {
                $handles[$handle] = true;
            }
        }
        return array_keys($handles);
    }

    /** Always-on filterable attributes plus whatever the admin configured. @return string[] */
    public function filterableAttributes(): array
    {
        return array_merge(
            ['source', 'type', 'sectionHandle', 'status', 'siteId', 'elementId'],
            $this->settings()->filterableAttributes,
        );
    }

    /** Always-on sortable attributes plus whatever the admin configured. @return string[] */
    public function sortableAttributes(): array
    {
        return array_merge(['postDate', 'title'], $this->settings()->sortableAttributes);
    }

    /**
     * The full Meilisearch index settings payload: the admin's `indexSettings`
     * verbatim, with our always-on filterable/sortable attributes merged in (so
     * the plugin's own filters/sorts keep working even if the admin also lists
     * their own).
     *
     * @return array<string,mixed>
     */
    public function indexSettingsPayload(): array
    {
        $payload = $this->settings()->indexSettings;

        $payload['filterableAttributes'] = array_values(array_unique(array_merge(
            $this->filterableAttributes(),
            $payload['filterableAttributes'] ?? [],
        )));
        $payload['sortableAttributes'] = array_values(array_unique(array_merge(
            $this->sortableAttributes(),
            $payload['sortableAttributes'] ?? [],
        )));

        return $payload;
    }

    /**
     * Create the index (if needed) and push its full settings configuration.
     *
     * @throws MeilisearchException
     */
    public function ensureIndex(): void
    {
        $this->client()->ensureIndex(
            $this->settings()->getIndexName(),
            self::PRIMARY_KEY,
            $this->indexSettingsPayload(),
        );
    }

    /**
     * Number of documents currently in the index, or null if the index doesn't
     * exist yet / can't be reached. Used by the Utilities screen.
     */
    public function documentCount(): ?int
    {
        try {
            $stats = $this->client()->stats($this->settings()->getIndexName());
            return isset($stats['numberOfDocuments']) ? (int) $stats['numberOfDocuments'] : null;
        } catch (MeilisearchException $e) {
            return null;
        }
    }

    /**
     * Whether an element matches any configured source (so save/delete hooks know
     * if it is something we mirror). Cheap class + section check, no query.
     */
    public function isIndexable(ElementInterface $element): bool
    {
        foreach ($this->getSources() as $source) {
            if (!$element instanceof $source['type']) {
                continue;
            }
            $sections = $source['criteria']['section'] ?? null;
            if ($sections === null) {
                return true;
            }
            $handle = $this->sectionHandle($element);
            if ($handle !== null && in_array($handle, (array) $sections, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The active-status list to apply to an element: the first matching source's
     * `statuses`, falling back to the plugin-wide default.
     *
     * @return string[]
     */
    private function statusesFor(ElementInterface $element): array
    {
        foreach ($this->getSources() as $source) {
            if (!$element instanceof $source['type']) {
                continue;
            }
            $sections = $source['criteria']['section'] ?? null;
            if ($sections === null) {
                return $source['statuses'];
            }
            $handle = $this->sectionHandle($element);
            if ($handle !== null && in_array($handle, (array) $sections, true)) {
                return $source['statuses'];
            }
        }
        return $this->settings()->activeStatuses;
    }

    /**
     * Build the Meilisearch document for one element. Returns null when the
     * element should not be indexed (e.g. it has no usable status/title).
     *
     * @return array<string,mixed>|null
     */
    public function buildDocument(ElementInterface $element): ?array
    {
        $doc = [
            self::PRIMARY_KEY => $this->objectId($element),
            'elementId'       => (int) $element->id,
            'siteId'          => (int) $element->siteId,
            'source'          => $this->sourceKey($element),
            'type'            => get_class($element),
            'sectionHandle'   => $this->sectionHandle($element),
            'status'          => $element->getStatus(),
            'title'           => (string) $element->title,
            'slug'            => (string) ($element->slug ?? ''),
            'url'             => $element->getUrl(),
            'postDate'        => isset($element->postDate) ? $element->postDate->getTimestamp() : null,
        ];

        foreach ($this->customFieldHandles() as $handle) {
            try {
                $doc[$handle] = $this->stringifyValue($element->getFieldValue($handle));
            } catch (\Throwable $e) {
                // Field doesn't exist on this element type — skip it silently.
                continue;
            }
        }

        return $doc;
    }

    /**
     * Index a single element (or remove it from the index if it is no longer
     * live/enabled). Honours the queueSync setting by routing through a job.
     *
     * @throws MeilisearchException
     */
    public function indexElement(ElementInterface $element): void
    {
        // Only index the canonical element that is actually visible; drafts,
        // revisions and not-active elements (disabled/pending/expired) are removed
        // instead so search stays clean. Which statuses count as "active" is
        // configurable per source (default: live/enabled/active).
        $visible = $element->getIsCanonical()
            && !$element->getIsDraft()
            && !$element->getIsRevision()
            && in_array($element->getStatus(), $this->statusesFor($element), true);

        if (!$visible) {
            $this->deleteElement($element);
            return;
        }

        $doc = $this->buildDocument($element);
        if ($doc === null) {
            return;
        }

        $this->client()->addDocuments($this->settings()->getIndexName(), [$doc], self::PRIMARY_KEY);
    }

    /** Remove a single element from the index. @throws MeilisearchException */
    public function deleteElement(ElementInterface $element): void
    {
        $this->client()->deleteDocument($this->settings()->getIndexName(), $this->objectId($element));
    }

    /**
     * Full reindex of every source. Returns the total number of documents sent.
     * Pass a $progress callback (string $message) for console output.
     *
     * @throws MeilisearchException
     */
    public function reindexAll(?callable $progress = null): int
    {
        $this->ensureIndex();

        $indexName = $this->settings()->getIndexName();
        $total = 0;
        $batch = [];

        foreach ($this->getSources() as $source) {
            $query = $this->queryForSource($source);
            $label = $this->classShortName($source['type']);
            $progress && $progress("Indexing {$label}…");

            foreach ($query->each() as $element) {
                $doc = $this->buildDocument($element);
                if ($doc === null) {
                    continue;
                }
                $batch[] = $doc;
                $total++;

                if (count($batch) >= 1000) {
                    $this->client()->addDocuments($indexName, $batch, self::PRIMARY_KEY);
                    $batch = [];
                    $progress && $progress("  …{$total} documents sent");
                }
            }
        }

        if ($batch) {
            $this->client()->addDocuments($indexName, $batch, self::PRIMARY_KEY);
        }

        return $total;
    }

    /** Drop and recreate the index, then reindex from scratch. @throws MeilisearchException */
    public function flushAndReindex(?callable $progress = null): int
    {
        $progress && $progress('Deleting existing index…');
        $this->client()->deleteIndex($this->settings()->getIndexName());
        return $this->reindexAll($progress);
    }

    // -- internals -----------------------------------------------------------

    /** Build the element query for a source with its criteria applied to every site. */
    private function queryForSource(array $source): ElementQueryInterface
    {
        /** @var class-string<ElementInterface> $class */
        $class = $source['type'];
        $query = $class::find();

        $criteria = $source['criteria'] ?? [];
        // Default to every editable site unless the criteria pins it down.
        if (!isset($criteria['siteId']) && !isset($criteria['site'])) {
            $criteria['siteId'] = '*';
        }
        Craft::configure($query, $criteria);

        return $query;
    }

    private function objectId(ElementInterface $element): string
    {
        return $element->siteId . '_' . $element->id;
    }

    /** Which configured source key an element belongs to (its section, else class). */
    private function sourceKey(ElementInterface $element): string
    {
        return $this->sectionHandle($element) ?? $this->classShortName(get_class($element));
    }

    /** Section handle for entries (and anything exposing getSection()), else null. */
    private function sectionHandle(ElementInterface $element): ?string
    {
        if (method_exists($element, 'getSection')) {
            try {
                $section = $element->getSection();
                return $section?->handle;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    private function classShortName(string $class): string
    {
        $parts = explode('\\', $class);
        return lcfirst(end($parts));
    }

    /**
     * Reduce an arbitrary field value to indexable text. Scalars pass through;
     * element queries become a space-joined list of related titles; everything
     * else is coerced via __toString or JSON as a last resort.
     */
    private function stringifyValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof ElementQueryInterface) {
            $titles = [];
            foreach ($value->all() as $related) {
                $titles[] = (string) $related->title;
            }
            return implode(' ', $titles);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_array($value)) {
            return trim(strip_tags(implode(' ', array_map(
                static fn($v) => is_scalar($v) ? (string) $v : '',
                $value,
            ))));
        }

        return null;
    }
}
