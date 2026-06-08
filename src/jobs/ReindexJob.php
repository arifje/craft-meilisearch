<?php

namespace arifje\craftmeilisearch\jobs;

use arifje\craftmeilisearch\Plugin;
use craft\queue\BaseJob;

/**
 * Full (re)index of every configured source on the queue — what the Utilities
 * screen's "Reindex" / "Flush & reindex" buttons push, so the CP request returns
 * immediately while a potentially long job runs in the background.
 */
class ReindexJob extends BaseJob
{
    /** When true, drop the index first and rebuild from scratch. */
    public bool $flush = false;

    public function execute($queue): void
    {
        $index = Plugin::getInstance()->index;
        $this->flush ? $index->flushAndReindex() : $index->reindexAll();
    }

    protected function defaultDescription(): ?string
    {
        return $this->flush
            ? 'Flushing and reindexing Meilisearch'
            : 'Reindexing Meilisearch';
    }
}
