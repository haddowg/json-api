<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * A custom action's handler that declares the members of its response document's top-level
 * `meta`, per response status, as a resource's
 * {@see \haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface::documentMeta()} does per
 * operation. An adapter reads it when it builds the action's metadata, and maps each
 * status's members onto the action's declared {@see \haddowg\JsonApi\OpenApi\Metadata\ActionResource},
 * {@see \haddowg\JsonApi\OpenApi\Metadata\ActionCreated} or
 * {@see \haddowg\JsonApi\OpenApi\Metadata\MetaResult} through
 * {@see \haddowg\JsonApi\OpenApi\Metadata\ActionResponses::withDocumentMeta()}.
 */
interface DeclaresActionDocumentMetaInterface
{
    /**
     * The members of the top-level `meta` of the document the action answers `$status`
     * with, as the field builders an attribute takes. A member's `extractUsing()` hook
     * supplies its value from the response's primary data and the request; a member with
     * none is set by the handler with `withMeta()`.
     *
     * It is called without a request, when the adapter builds the action's metadata, so
     * the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function documentMeta(int $status): iterable;
}
