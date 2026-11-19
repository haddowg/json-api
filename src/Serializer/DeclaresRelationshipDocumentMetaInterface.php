<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\RelationshipMetaContext;

/**
 * A type whose relationship writes may answer a meta-only `200`, declaring that document's
 * top-level `meta` per relationship and mutation, as
 * {@see DeclaresDocumentMetaInterface::documentMeta()} does per resource operation.
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it with no members; a type
 * composed without one implements it on its serializer.
 *
 * An adapter reads it through {@see \haddowg\JsonApi\Resource\DocumentMeta::forRelationship()}.
 */
interface DeclaresRelationshipDocumentMetaInterface
{
    /**
     * The members of the top-level `meta` of the meta-only `200` the relationship write
     * `$context` describes answers with. Declaring any makes that write answer the
     * meta-only document instead of its linkage. A member's `extractUsing()` hook receives
     * the parent resource and the request; a member with none is set by a hook through
     * `withMeta()`.
     *
     * The OpenAPI projection calls it with no request, so the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function relationshipDocumentMeta(RelationshipMetaContext $context): iterable;
}
