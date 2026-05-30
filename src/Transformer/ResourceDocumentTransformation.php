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
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
class ResourceDocumentTransformation extends AbstractDocumentTransformation
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
    ) {
        parent::__construct($document, $request, $additionalMeta);
    }
}
