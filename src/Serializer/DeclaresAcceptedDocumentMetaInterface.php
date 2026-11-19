<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * A type whose create or update may be accepted for later processing, declaring the
 * top-level `meta` of the job document its `202` answers with, as
 * {@see \haddowg\JsonApi\OpenApi\Metadata\Accepted}'s `meta` does where a response object
 * can hold field builders. {@see \haddowg\JsonApi\Resource\AbstractResource} implements it
 * with no members; a type composed without one implements it on its serializer.
 *
 * An adapter reads it through {@see \haddowg\JsonApi\Resource\DocumentMeta::forAccepted()}.
 */
interface DeclaresAcceptedDocumentMetaInterface
{
    /**
     * The members of the top-level `meta` of the `202` the write `$operation`, a create or
     * an update, answers with. A member's `extractUsing()` hook receives the job resource
     * and the request; a member with none is set on the response by the code returning it.
     *
     * The OpenAPI projection calls it with no request, so the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function acceptedDocumentMeta(OperationType $operation): iterable;
}
