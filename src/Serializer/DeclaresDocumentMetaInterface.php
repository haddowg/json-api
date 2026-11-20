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
     * so the result must not depend on one. A delete's members apply when it answers a
     * meta-only `200`, and its hooks receive the resource it removed; a `204` has no
     * document. A related endpoint serving the type takes
     * its `FetchCollection` members for a to-many and its `FetchOne` members for a to-one.
     *
     * The relationship write cases declare the meta-only `200` that write answers with in
     * place of its linkage; their hooks receive the parent resource. `$relation` is the name
     * of the relation written for a relationship write case and `null` for every other
     * case, so an implementation can declare members for one relationship only. One that
     * ignores it declares the same members for every relationship of the type.
     * `CreateAccepted` and `UpdateAccepted` declare the job document a `202` answers with;
     * their hooks receive the job resource.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function documentMeta(OperationType $operation, ?string $relation = null): iterable;
}
