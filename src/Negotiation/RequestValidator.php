<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Validates JSON:API content-negotiation constraints on an incoming request.
 *
 * Phase-1 trimmed port: only Content-Type/Accept negotiation, query-param
 * validation, and top-level-member validation are performed. JSON-schema body
 * linting (validateJsonApiBody) is deferred to a later phase. JSON well-
 * formedness (validateJsonBody) is intentionally delegated: calling
 * getParsedBody() on a JsonApiRequest already throws RequestBodyInvalidJson
 * when the raw body is not valid JSON, so this method is a thin trigger that
 * surfaces that exception to callers who call it explicitly.
 *
 * Constructor shape change from yin: SerializerInterface and
 * ExceptionFactoryInterface have been removed; exceptions are thrown directly
 * as typed instances. The $includeOriginalMessageInResponse parameter has been
 * dropped because it only affected exception construction, which is now inline.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
final class RequestValidator
{
    /**
     * Validates the Content-Type and Accept headers of the request against the
     * JSON:API media type rules.
     *
     * @throws MediaTypeUnsupported
     * @throws MediaTypeUnacceptable
     */
    public function negotiate(JsonApiRequestInterface $request): void
    {
        $request->validateContentTypeHeader();
        $request->validateAcceptHeader();
    }

    /**
     * Validates query parameters on the request.
     *
     * @throws QueryParamUnrecognized
     */
    public function validateQueryParams(JsonApiRequestInterface $request): void
    {
        $request->validateQueryParams();
    }

    /**
     * Triggers JSON well-formedness decoding of the request body.
     *
     * The underlying JsonApiRequest::getParsedBody() decodes the raw body and
     * throws RequestBodyInvalidJson when the body is malformed JSON. This method
     * exists so callers have an explicit validation entry-point; it does not
     * perform any additional JSON-schema linting (deferred to Phase 2).
     *
     * @throws \haddowg\JsonApi\Exception\RequestBodyInvalidJson
     */
    public function validateJsonBody(JsonApiRequestInterface $request): void
    {
        $request->getParsedBody();
    }

    /**
     * Validates the top-level members of the request body against the JSON:API
     * structure rules.
     *
     * @throws \haddowg\JsonApi\Exception\RequiredTopLevelMembersMissing
     * @throws \haddowg\JsonApi\Exception\TopLevelMembersIncompatible
     * @throws \haddowg\JsonApi\Exception\TopLevelMemberNotAllowed
     */
    public function validateTopLevelMembers(JsonApiRequestInterface $request): void
    {
        $request->validateTopLevelMembers();
    }
}
