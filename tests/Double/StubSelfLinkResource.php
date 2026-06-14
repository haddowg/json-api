<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\SelfLinkAwareInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;

/**
 * A configurable {@see AbstractSerializer} that is both URI-type-aware and
 * self-link-aware, for exercising the by-convention resource `self` link: a
 * distinct `uriType` (decoupled from the JSON:API `type`) and the
 * {@see SelfLinkAwareInterface} opt-out.
 */
final class StubSelfLinkResource extends AbstractSerializer implements UriTypeAwareInterface, SelfLinkAwareInterface
{
    public function __construct(
        private readonly string $type = '',
        private readonly string $id = '',
        private readonly string $uriType = '',
        private readonly bool $emitsSelfLink = true,
        private readonly ?ResourceLinks $links = null,
    ) {}

    public function getType(mixed $object): string
    {
        return $this->type;
    }

    public function uriType(): string
    {
        return $this->uriType !== '' ? $this->uriType : $this->type;
    }

    public function emitsSelfLink(): bool
    {
        return $this->emitsSelfLink;
    }

    public function getId(mixed $object): string
    {
        return $this->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->links;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
