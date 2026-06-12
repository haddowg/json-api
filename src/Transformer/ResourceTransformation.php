<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Mutable per-resource state threaded through the {@see ResourceTransformer}
 * during a single serialization pass. Not readonly: fields such as
 * `$object`, `$resourceType`, `$currentRelationshipName` and `$result` are
 * reassigned as the transformer walks resources and relationships.
 *
 * @internal
 *
 */
final class ResourceTransformation
{
    /**
     * The accumulated JSON:API resource (or resource identifier) representation.
     *
     * @var array<string, mixed>|null
     */
    public ?array $result = null;

    public function __construct(
        public ?SerializerInterface $resource,
        public mixed $object,
        public string $resourceType,
        public JsonApiRequestInterface $request,
        public string $basePath,
        public string $requestedRelationshipName,
        public string $currentRelationshipName,
        public string $baseUri = '',
    ) {}
}
