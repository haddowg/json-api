<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * Opt-in for an {@see ActionMetadataInterface} whose request document declares its
 * top-level `meta`, as a handler implementing
 * {@see \haddowg\JsonApi\Resource\DeclaresActionRequestMetaInterface} does. An adapter's
 * action metadata delegates to the handler.
 */
interface DeclaresActionRequestMeta
{
    /**
     * The members of the top-level `meta` a call with the method `$context` names takes. It
     * is called without a request, so the result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function requestDocumentMeta(ActionRequestMetaContext $context): iterable;
}
