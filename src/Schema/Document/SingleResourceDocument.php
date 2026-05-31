<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Resource\ResourceInterface;

/**
 * Concrete single-resource document constructed by the response value objects.
 *
 * yin's document hierarchy stops at the abstract bases (consumers subclass
 * them); this library drives serialization from response value objects instead,
 * so it provides concrete documents carrying the top-level members directly.
 *
 * @internal
 */
final class SingleResourceDocument extends AbstractSingleResourceDocument
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        ResourceInterface $resource,
        private readonly ?JsonApiObject $jsonApi,
        private readonly array $meta,
        private readonly ?DocumentLinks $links,
    ) {
        parent::__construct($resource);
    }

    public function getJsonApi(): ?JsonApiObject
    {
        return $this->jsonApi;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getLinks(): ?DocumentLinks
    {
        return $this->links;
    }
}
