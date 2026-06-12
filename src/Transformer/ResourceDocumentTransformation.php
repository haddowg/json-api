<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Document\ResourceDocumentInterface;

/**
 * Document-transformation state for resource, meta and relationship documents.
 * Carries the primary domain object plus the relationship-path context the
 * transformer needs to resolve inclusion.
 *
 * @internal
 *
 * @extends AbstractDocumentTransformation<ResourceDocumentInterface>
 *
 */
final class ResourceDocumentTransformation extends AbstractDocumentTransformation
{
    /**
     * @param array<string, mixed> $additionalMeta
     */
    public function __construct(
        ResourceDocumentInterface $document,
        public mixed $object,
        JsonApiRequestInterface $request,
        public string $basePath,
        public string $requestedRelationshipName,
        array $additionalMeta,
        public string $baseUri = '',
    ) {
        parent::__construct($document, $request, $additionalMeta);
    }
}
