<?php

namespace arifje\craftmeilisearch\controllers;

use arifje\craftmeilisearch\Plugin;
use arifje\craftmeilisearch\services\MeilisearchException;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Front-end search proxy.
 *
 *   GET|POST meilisearch/search/query?q=…   → Meilisearch search results as JSON
 *
 * The browser never sees the Meilisearch host or key — the request is proxied
 * here using the search-only key. The bundled Vue component talks to this action.
 * Anonymous access is allowed (it's public site search) but can be switched off
 * with the `searchEndpointEnabled` setting.
 */
class SearchController extends Controller
{
    protected int|bool|array $allowAnonymous = true;

    /** Hard cap on `limit` so a caller can't ask Meilisearch for everything. */
    private const MAX_LIMIT = 100;

    public function actionQuery(): Response
    {
        $this->requireAcceptsJson();

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->searchEndpointEnabled) {
            throw new ForbiddenHttpException('The search endpoint is disabled.');
        }
        if (!$settings->isConfigured()) {
            throw new ForbiddenHttpException('Meilisearch is not configured.');
        }

        $request = Craft::$app->getRequest();
        $q       = (string) ($request->getParam('q') ?? '');

        $limit  = min(self::MAX_LIMIT, max(1, (int) ($request->getParam('limit') ?? 20)));
        $offset = max(0, (int) ($request->getParam('offset') ?? 0));

        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        // Optional, sanitised pass-throughs. `filter` is accepted as a string or
        // array exactly as Meilisearch expects it; callers that want to restrict
        // by section/site send e.g. filter=sectionHandle = "news".
        foreach (['filter', 'sort', 'facets', 'attributesToRetrieve', 'attributesToHighlight'] as $param) {
            $value = $request->getParam($param);
            if ($value !== null && $value !== '') {
                $params[$param] = $value;
            }
        }

        // Default to highlighting the title so the component can show matches.
        if (!isset($params['attributesToHighlight'])) {
            $params['attributesToHighlight'] = ['title'];
        }

        try {
            $results = Plugin::getInstance()->meilisearch->search(
                $settings->getIndexName(),
                $q,
                $params,
            );
        } catch (MeilisearchException $e) {
            Craft::error('Meilisearch query failed: ' . $e->getMessage(), __METHOD__);
            throw new BadRequestHttpException('Search failed.');
        }

        return $this->asJson($results);
    }
}
