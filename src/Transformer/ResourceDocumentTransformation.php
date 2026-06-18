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
    /**
     * @param bool|null $countableSelfOverride when non-null, the `?withCount=_self_`
     *                                         countability for this document, supplied
     *                                         by the caller instead of read from the
     *                                         primary serializer — a related-collection
     *                                         render ({@see \haddowg\JsonApi\Response\RelatedResponse})
     *                                         passes the owning relation's `countable()`
     *                                         so `_self_` is gated by the *relation*, not
     *                                         the related resource. `null` (the default)
     *                                         falls back to the primary serializer's own
     *                                         {@see \haddowg\JsonApi\Serializer\CountableSelfInterface::isCountable()}.
     */
    public function __construct(
        ResourceDocumentInterface $document,
        public mixed $object,
        JsonApiRequestInterface $request,
        public string $basePath,
        public string $requestedRelationshipName,
        array $additionalMeta,
        public string $baseUri = '',
        public ?int $maxIncludeDepth = null,
        public ?bool $countableSelfOverride = null,
    ) {
        parent::__construct($document, $request, $additionalMeta);
    }
}
