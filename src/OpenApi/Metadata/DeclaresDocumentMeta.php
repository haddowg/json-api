<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * Opt-in for a {@see TypeMetadataInterface} whose documents declare their top-level `meta`
 * per operation, as {@see \haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface} does
 * on the type itself. An adapter's metadata delegates to the type's declaration.
 *
 * Each operation that returns members answers with a document component of its own, named
 * for the type and the operation, that narrows the shared document's `meta` to a closed
 * object of those members.
 */
interface DeclaresDocumentMeta
{
    /**
     * The members of the top-level `meta` of the document `$operation` answers with,
     * including a relationship write's meta-only `200` and a write's `202` job document. It
     * is called without a request, so the result must not depend on one.
     *
     * As in {@see \haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface::documentMeta()},
     * `$relation` is the relation's name for a relationship write case and `null` otherwise.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function documentMeta(OperationType $operation, ?string $relation = null): iterable;
}
