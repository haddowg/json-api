<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Document\AbstractCollectionDocument;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Configurable {@see AbstractCollectionDocument} test double.
 */
final class StubCollectionDocument extends AbstractCollectionDocument
{
    /**
     * @param array<string, mixed> $meta
     * @param iterable<mixed>      $object
     */
    public function __construct(
        private readonly ?JsonApiObject $jsonApi = null,
        private readonly array $meta = [],
        private readonly ?DocumentLinks $links = null,
        ?SerializerInterface $resource = null,
        iterable $object = [],
    ) {
        parent::__construct($resource ?? new StubResource());
        $this->object = $object;
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

    public function getHasItems(): bool
    {
        return $this->hasItems();
    }
}
