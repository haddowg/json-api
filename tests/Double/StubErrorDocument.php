<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Document\AbstractErrorDocument;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;

/**
 * Configurable {@see AbstractErrorDocument} test double.
 */
final class StubErrorDocument extends AbstractErrorDocument
{
    /**
     * @param array<string, mixed> $meta
     * @param list<Error>          $errors
     */
    public function __construct(
        private readonly ?JsonApiObject $jsonApi = null,
        private readonly array $meta = [],
        private readonly ?DocumentLinks $links = null,
        array $errors = [],
    ) {
        $this->errors = $errors;
    }

    public function getJsonApi(): ?JsonApiObject
    {
        return $this->jsonApi;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getLinks(): ?DocumentLinks
    {
        return $this->links;
    }
}
