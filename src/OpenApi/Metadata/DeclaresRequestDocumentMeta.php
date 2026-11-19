<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\RequestMetaContext;

/**
 * Opt-in for a {@see TypeMetadataInterface} whose write requests declare their top-level
 * `meta`, as {@see \haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface} does on
 * the type itself. An adapter's metadata delegates to the type's declaration.
 *
 * Each write that returns members takes a request document component of its own, named
 * for the operation, that narrows the shared one's `meta` to the declared object.
 */
interface DeclaresRequestDocumentMeta
{
    /**
     * The members of the top-level `meta` the write `$context` takes. It is called without
     * a request, so the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function requestDocumentMeta(RequestMetaContext $context): iterable;
}
