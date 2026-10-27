<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * A JSON:API exception whose error response must carry HTTP headers, such as the
 * `WWW-Authenticate` challenge of {@see InsufficientScope}.
 * {@see \haddowg\JsonApi\Response\ErrorResponse::fromException()} applies them, and an
 * adapter rendering the exception its own way should too.
 */
interface CarriesResponseHeadersInterface extends JsonApiExceptionInterface
{
    /**
     * @return array<string, string> header name → value
     */
    public function responseHeaders(): array;
}
