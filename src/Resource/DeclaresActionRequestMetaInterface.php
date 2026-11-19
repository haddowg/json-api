<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * A custom action's handler that declares the members of its request document's top-level
 * `meta`, as a resource's
 * {@see \haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface::requestDocumentMeta()}
 * does per write. An adapter reads it when it builds the action's metadata and validates
 * each call's `meta` against it.
 */
interface DeclaresActionRequestMetaInterface
{
    /**
     * The members of the top-level `meta` a call with the method `$context` names may
     * carry, as the field builders an attribute takes. Declaring nothing leaves `meta` open
     * and unchecked for that method.
     *
     * It is called without a request, when the adapter builds the action's metadata, so the
     * result must not depend on one.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function requestDocumentMeta(ActionRequestMetaContext $context): iterable;
}
