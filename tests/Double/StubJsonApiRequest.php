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
     * @param array<string, mixed> $queryParams
     */
    public static function create(array $queryParams = []): self
    {
        return new self($queryParams);
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public function __construct(array $queryParams = [])
    {
        parent::__construct(
            (new ServerRequest('GET', '/'))->withQueryParams($queryParams),
        );
    }
}
