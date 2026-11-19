<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\MetaContext;

/**
 * Opt-in for a {@see TypeMetadataInterface} whose resource objects declare their `meta`, as
 * {@see \haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface} does on the type itself.
 * An adapter's metadata delegates to the type's declaration.
 *
 * The type's resource object and its write requests' `data` then type `meta` with the
 * declared object for their direction.
 */
interface DeclaresResourceMeta
{
    /**
     * The members of a resource object's `meta` in the direction `$context` describes. It is
     * called without a request, so the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function resourceMeta(MetaContext $context): iterable;
}
