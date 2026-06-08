<?php

namespace arifje\craftmeilisearch;

use arifje\craftmeilisearch\jobs\IndexElementJob;
use arifje\craftmeilisearch\models\Settings;
use arifje\craftmeilisearch\services\IndexService;
use arifje\craftmeilisearch\services\Meilisearch;
use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\ElementEvent;
use craft\services\Elements;
use yii\base\Event;

/**
 * Meilisearch plugin.
 *
 * Mirrors Craft elements into a hosted Meilisearch daemon and proxies front-end
 * searches back through Craft. Two services do the work:
 *
 *   - {@see Meilisearch}  — the HTTP client to the daemon
 *   - {@see IndexService} — turns elements into documents and keeps them in sync
 *
 * Indexing happens automatically on element save/delete (configurable) and can
 * be driven from the console: `php craft meilisearch/index/reindex`.
 *
 * Works on Craft 4 and Craft 5 / PHP 8.0.2+.
 *
 * @property-read Meilisearch $meilisearch
 * @property-read IndexService $index
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'meilisearch' => Meilisearch::class,
                'index'       => IndexService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Expose the console commands under `meilisearch/…`.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'arifje\\craftmeilisearch\\console\\controllers';
        }

        $this->attachElementSyncHandlers();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();

        // Probe the connection so the admin gets immediate feedback. Skipped when
        // nothing is configured yet to avoid a guaranteed-failing request.
        $health = $settings->isConfigured()
            ? $this->meilisearch->health()
            : ['ok' => false, 'message' => 'Enter a host URL and API key, then save.'];

        return Craft::$app->getView()->renderTemplate('meilisearch/settings', [
            'plugin'   => $this,
            'settings' => $settings,
            'health'   => $health,
            'sources'  => $this->index->getSources(),
        ]);
    }

    /**
     * Keep Meilisearch in sync by listening to element save/delete/restore.
     * Each handler is a cheap guard (is sync on? is this element a configured
     * source?) before either pushing a job or indexing inline.
     */
    private function attachElementSyncHandlers(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function (ElementEvent $event): void {
            $this->syncElement($event->element, delete: false);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function (ElementEvent $event): void {
            $this->syncElement($event->element, delete: false);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function (ElementEvent $event): void {
            $this->syncElement($event->element, delete: true);
        });
    }

    /** Route one element change to the queue or run it inline, per settings. */
    private function syncElement(ElementInterface $element, bool $delete): void
    {
        $settings = $this->getSettings();
        if (!$settings->syncOnSave || !$settings->isConfigured()) {
            return;
        }

        // Drafts and revisions are never indexed; ignore them outright on save so
        // we don't enqueue a no-op job for every autosave keystroke.
        if (!$delete && ($element->getIsDraft() || $element->getIsRevision() || !$element->getIsCanonical())) {
            return;
        }

        if (!$this->index->isIndexable($element)) {
            return;
        }

        if ($settings->queueSync) {
            Craft::$app->getQueue()->push(new IndexElementJob([
                'elementId'   => (int) $element->id,
                'siteId'      => (int) $element->siteId,
                'elementType' => get_class($element),
                'delete'      => $delete,
            ]));
            return;
        }

        // Inline: never let a search-index hiccup break the content save.
        try {
            $delete ? $this->index->deleteElement($element) : $this->index->indexElement($element);
        } catch (\Throwable $e) {
            Craft::error('Meilisearch sync failed: ' . $e->getMessage(), __METHOD__);
        }
    }
}
