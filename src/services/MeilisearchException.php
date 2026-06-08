<?php

namespace arifje\craftmeilisearch\services;

use Throwable;
use yii\base\Exception;

/**
 * Raised by {@see Meilisearch} for any transport failure or non-2xx response.
 * Carries the HTTP status and Meilisearch's own machine-readable error code so
 * callers can react to specific conditions (e.g. `index_already_exists`).
 */
class MeilisearchException extends Exception
{
    /** HTTP status code of the failed response, or 0 for a transport error. */
    public int $statusCode;

    /** Meilisearch error code (e.g. `index_not_found`), when the body had one. */
    public ?string $meiliCode;

    public function __construct(string $message = '', int $statusCode = 0, ?Throwable $previous = null, ?string $meiliCode = null)
    {
        $this->statusCode = $statusCode;
        $this->meiliCode  = $meiliCode;
        parent::__construct($message, 0, $previous);
    }

    public function getName(): string
    {
        return 'Meilisearch Error';
    }
}
