<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * A type that declares the members of its documents' top-level `meta`, per operation.
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it with no members; a type
 * composed without one implements it on its serializer.
 *
 * An adapter reads it through {@see \haddowg\JsonApi\Resource\DocumentMeta::forOperation()}.
 */
interface DeclaresDocumentMetaInterface
{
    /**
     * The members of the top-level `meta` of the document `$operation` answers with, as the
     * field builders an attribute takes. A member declared `required()` is in every such
     * document; any other is optional. A member's `extractUsing()` hook supplies its value
     * from the document's primary data and the request; a member with none is set by the
     * handler or an After hook through `withMeta()`.
     *
     * The OpenAPI projection calls it once per operation the type exposes, with no request,
     * so the result must not depend on one. A delete is never asked: it answers `204` or a
     * meta-only document its `MetaResult` declares. A related endpoint serving the type takes
     * its `FetchCollection` members for a to-many and its `FetchOne` members for a to-one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function documentMeta(OperationType $operation): iterable;
}
