<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\MetaContext;

/**
 * A type that declares the members of its resource objects' `meta`, read and written.
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it with no members; a type
 * composed without one implements it on its serializer, and on its hydrator too when its
 * writes take `data.meta`.
 *
 * An adapter reads it through {@see \haddowg\JsonApi\Resource\ResourceMeta::forType()}.
 */
interface DeclaresResourceMetaInterface
{
    /**
     * The members of a resource object's `meta` in the direction `$context` describes, as
     * the field builders an attribute takes. A read renders each member whose
     * `extractUsing()` hook supplies it, from the model, beside what `getMeta()` returns; a
     * write validates `data.meta` as attributes are validated and hands each member to its
     * `fillUsing()` hook, or leaves it on the written meta when it has none. `readOnly()`
     * and `writeOnly()` split one list between the directions. A member the declaration
     * does not name is refused on a write, and flagged by an adapter's debug check on a
     * read, unless an {@see \haddowg\JsonApi\Resource\Field\AdditionalProperties} entry
     * opens the object. Declaring nothing leaves `meta` open in both directions.
     *
     * The OpenAPI projection calls it with no request, so the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function resourceMeta(MetaContext $context): iterable;
}
