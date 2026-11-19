<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\RequestMetaContext;

/**
 * A type that declares the members of its write requests' top-level `meta`, per write.
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it with no members; a type
 * composed without one implements it on its hydrator.
 *
 * An adapter reads it through {@see \haddowg\JsonApi\Resource\RequestMeta::forOperation()}.
 */
interface DeclaresRequestDocumentMetaInterface
{
    /**
     * The members of the top-level `meta` the write `$context` describes may carry, as the
     * field builders an attribute takes. A member declared `required()` must be sent; any
     * other may be. Values are validated as attributes are, and a member the declaration
     * does not name is refused unless an {@see \haddowg\JsonApi\Resource\Field\AdditionalProperties}
     * entry opens the object. Declaring nothing leaves `meta` open and unchecked.
     *
     * The OpenAPI projection calls it once per write the type exposes, with no request, so
     * the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function requestDocumentMeta(RequestMetaContext $context): iterable;
}
