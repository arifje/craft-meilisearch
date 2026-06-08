<?php

namespace arifje\craftmeilisearch\jobs;

use arifje\craftmeilisearch\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Index (or remove) a single element in Meilisearch off the request thread.
 *
 * Pushed by the element save/delete hooks when `queueSync` is on. Carries only
 * ids so the payload stays small and the element is re-fetched fresh at run time.
 */
class IndexElementJob extends BaseJob
{
    public int $elementId;
    public ?int $siteId = null;
    public string $elementType = \craft\elements\Entry::class;

    /** When true the element is deleted from the index rather than (re)indexed. */
    public bool $delete = false;

    public function execute($queue): void
    {
        $index = Plugin::getInstance()->index;

        if ($this->delete) {
            // The element may already be gone; reconstruct a lightweight stub so
            // we can compute its objectID without a DB row.
            $element = Craft::$app->getElements()->getElementById(
                $this->elementId,
                $this->elementType,
                $this->siteId,
            );
            if ($element !== null) {
                $index->deleteElement($element);
            } else {
                // Element row is gone — delete by composite key directly.
                Plugin::getInstance()->meilisearch->deleteDocument(
                    Plugin::getInstance()->getSettings()->getIndexName(),
                    ($this->siteId ?? '') . '_' . $this->elementId,
                );
            }
            return;
        }

        $element = Craft::$app->getElements()->getElementById(
            $this->elementId,
            $this->elementType,
            $this->siteId,
        );
        if ($element !== null) {
            $index->indexElement($element);
        }
    }

    protected function defaultDescription(): ?string
    {
        return $this->delete
            ? 'Removing element from Meilisearch'
            : 'Indexing element in Meilisearch';
    }
}
