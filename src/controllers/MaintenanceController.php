<?php

namespace arifje\craftmeilisearch\controllers;

use arifje\craftmeilisearch\jobs\ReindexJob;
use arifje\craftmeilisearch\utilities\Indices;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP-only actions behind the buttons on the Meilisearch Utilities screen.
 *
 *   POST meilisearch/maintenance/reindex   enqueue a (re)index of every source
 *   POST meilisearch/maintenance/flush     enqueue a flush + rebuild
 *
 * Both require a logged-in user with access to the utility and push a
 * {@see ReindexJob}, then redirect back with a flash notice.
 */
class MaintenanceController extends Controller
{
    public function actionReindex(): ?Response
    {
        return $this->enqueue(false);
    }

    public function actionFlush(): ?Response
    {
        return $this->enqueue(true);
    }

    private function enqueue(bool $flush): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . Indices::id());

        Craft::$app->getQueue()->push(new ReindexJob(['flush' => $flush]));

        $this->setSuccessFlash($flush
            ? Craft::t('app', 'Flush & reindex queued.')
            : Craft::t('app', 'Reindex queued.'));

        return $this->redirectToPostedUrl();
    }
}
