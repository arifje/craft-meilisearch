<?php

namespace arifje\craftmeilisearch\console\controllers;

use arifje\craftmeilisearch\Plugin;
use arifje\craftmeilisearch\services\MeilisearchException;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Console commands for managing the Meilisearch index.
 *
 *   php craft meilisearch/index/health     check the connection
 *   php craft meilisearch/index/reindex    (re)index every configured source
 *   php craft meilisearch/index/flush      drop the index and reindex from scratch
 */
class IndexController extends Controller
{
    /** Print the connection status of the configured Meilisearch host. */
    public function actionHealth(): int
    {
        $health = Plugin::getInstance()->meilisearch->health();
        if ($health['ok']) {
            $this->stdout('✓ ' . $health['message'] . PHP_EOL, Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stderr('✗ ' . $health['message'] . PHP_EOL, Console::FG_RED);
        return ExitCode::UNAVAILABLE;
    }

    /** (Re)index every configured source, upserting documents into the index. */
    public function actionReindex(): int
    {
        return $this->rebuild(false);
    }

    /** Delete the index entirely and rebuild it from scratch. */
    public function actionFlush(): int
    {
        return $this->rebuild(true);
    }

    // Note: not named run() — that collides with the public yii\base\Controller::run().
    private function rebuild(bool $flush): int
    {
        $index = Plugin::getInstance()->index;
        $progress = fn(string $message) => $this->stdout($message . PHP_EOL);

        try {
            $count = $flush
                ? $index->flushAndReindex($progress)
                : $index->reindexAll($progress);
        } catch (MeilisearchException $e) {
            $this->stderr('✗ ' . $e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("✓ Done — {$count} documents queued for indexing." . PHP_EOL, Console::FG_GREEN);
        $this->stdout('Meilisearch processes them asynchronously; allow a moment before searching.' . PHP_EOL);
        return ExitCode::OK;
    }
}
