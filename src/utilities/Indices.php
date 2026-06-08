<?php

namespace arifje\craftmeilisearch\utilities;

use arifje\craftmeilisearch\Plugin;
use Craft;
use craft\base\Utility;

/**
 * "Meilisearch" Utilities screen (CP → Utilities → Meilisearch).
 *
 * Shows the live connection status, the configured index and its document count,
 * the sources being indexed, and Reindex / Flush buttons that enqueue a
 * {@see \arifje\craftmeilisearch\jobs\ReindexJob}.
 */
class Indices extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('app', 'Meilisearch');
    }

    public static function id(): string
    {
        return 'meilisearch';
    }

    public static function iconPath(): ?string
    {
        return Craft::getAlias('@arifje/craftmeilisearch/icon.svg') ?: null;
    }

    public static function contentHtml(): string
    {
        $plugin   = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $health = $settings->isConfigured()
            ? $plugin->meilisearch->health()
            : ['ok' => false, 'message' => 'Meilisearch is not configured.'];

        return Craft::$app->getView()->renderTemplate('meilisearch/utility', [
            'health'        => $health,
            'configured'    => $settings->isConfigured(),
            'indexName'     => $settings->getIndexName(),
            'documentCount' => $health['ok'] ? $plugin->index->documentCount() : null,
            'sources'       => $plugin->index->getSources(),
        ]);
    }
}
