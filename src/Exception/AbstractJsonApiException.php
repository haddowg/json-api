<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * Base for the typed JSON:API exception hierarchy.
 *
 * Concrete exceptions pass a human-readable message and the HTTP status code up
 * through the constructor; the status is retained and surfaced via
 * {@see getStatusCode()}. Each concrete exception implements {@see getErrors()}
 * to expose its JSON:API error data. An exception that stands in for another one (an
 * adapter translating its framework's access denial) passes it as `$previous`.
 */
abstract class AbstractJsonApiException extends \Exception implements \haddowg\JsonApi\Exception\JsonApiExceptionInterface
{
    public function __construct(string $message, private readonly int $statusCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
