<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Request\JsonApiRequest;
use Nyholm\Psr7\ServerRequest;

/**
 * Test double producing a {@see JsonApiRequest} backed by a Nyholm PSR-7
 * request with the given query parameters.
 */
final class StubJsonApiRequest extends JsonApiRequest
{
    /**
     * @param array<string, mixed>  $queryParams
     * @param array<string, string> $headers      e.g. an `Accept` carrying a
     *                                             negotiated `profile` parameter
     */
    public static function create(array $queryParams = [], array $headers = []): self
    {
        return new self($queryParams, $headers);
    }

    /**
     * @param array<string, mixed>  $queryParams
     * @param array<string, string> $headers      e.g. an `Accept` carrying a
     *                                             negotiated `profile` parameter
     */
    public function __construct(array $queryParams = [], array $headers = [])
    {
        parent::__construct(
            (new ServerRequest('GET', '/', $headers))->withQueryParams($queryParams),
        );
    }
}
