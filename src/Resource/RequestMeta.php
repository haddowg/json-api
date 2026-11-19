<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionRequestMeta;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRequestDocumentMeta;

/**
 * Reads the declared top-level `meta` of a write request, for the adapters and the OpenAPI
 * projection alike. Each factory answers an empty {@see MetaShape}, leaving `meta` open,
 * when the declarer declares nothing.
 *
 * An adapter validates a write's `meta` against the shape before hydrating: the members it
 * does not declare ({@see MetaShape::undeclared()}) and the values of the wrong type
 * ({@see MetaShape::mismatches()}) are `422`s at `/meta/<member>`, beside each member's
 * own constraints, and the typed values ({@see MetaShape::values()}) go on the
 * {@see WrittenMeta} the write's hooks read.
 */
final class RequestMeta
{
    /**
     * The members `$declarer`, a type's hydrator or its metadata, declares for the write
     * `$context` describes.
     *
     * @throws MetaMisdeclared
     */
    public static function forOperation(object $declarer, RequestMetaContext $context, string $type = ''): MetaShape
    {
        $owner = \sprintf('the "%s" %s request', $type, $context->describe());
        if (!($declarer instanceof DeclaresRequestDocumentMetaInterface || $declarer instanceof DeclaresRequestDocumentMeta)) {
            return MetaShape::none($owner);
        }

        return MetaShape::of($declarer->requestDocumentMeta($context), $owner);
    }

    /**
     * The members an action's handler, or its metadata, declares for a call with the method
     * `$context` names.
     *
     * @throws MetaMisdeclared
     */
    public static function forAction(object $declarer, ActionRequestMetaContext $context, string $owner = 'the action'): MetaShape
    {
        $owner = \sprintf('the %s %s request', $context->method, $owner);
        if (!($declarer instanceof DeclaresActionRequestMetaInterface || $declarer instanceof DeclaresActionRequestMeta)) {
            return MetaShape::none($owner);
        }

        return MetaShape::of($declarer->requestDocumentMeta($context), $owner);
    }
}
