<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The request-side validation extension point: Content-Type/Accept negotiation,
 * query-param validation, JSON well-formedness, and top-level-member validation.
 * JSON-schema body linting is a separate concern ({@see \haddowg\JsonApi\Validation\DocumentValidator}).
 *
 * The shipped middleware compose these methods ({@see \haddowg\JsonApi\Middleware\ContentNegotiationMiddleware}
 * runs negotiation + query params; {@see \haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware}
 * runs well-formedness + top-level members), and a framework integration can
 * reuse the same methods in its own middleware. `validateJsonBody()` is a thin
 * trigger: calling getParsedBody() on a JsonApiRequest already throws
 * RequestBodyInvalidJson when the raw body is not valid JSON.
 *
 * Exceptions are thrown directly as typed instances.
 */
final class RequestValidator
{
    /**
     * @var list<string>
     */
    private readonly array $supportedExtensions;

    /**
     * @param string ...$supportedExtensions the extension URIs this server supports;
     *                                        none (the default) means any `ext`
     *                                        parameter is rejected
     */
    public function __construct(string ...$supportedExtensions)
    {
        $this->supportedExtensions = \array_values($supportedExtensions);
    }

    /**
     * Validates the Content-Type and Accept headers of the request against the
     * JSON:API media type rules: parameter well-formedness (only `ext`/`profile`
     * allowed) and extension support.
     *
     * Profiles are advisory and never rejected here — a server MUST ignore any
     * profile it does not recognize. Only unsupported **extensions** fail
     * negotiation: an unsupported `ext` on `Content-Type` yields `415`, and an
     * unsupported `ext` on `Accept` yields `406`.
     *
     * @throws MediaTypeUnsupported
     * @throws MediaTypeUnacceptable
     */
    public function negotiate(JsonApiRequestInterface $request): void
    {
        $request->validateContentTypeHeader();
        $request->validateAcceptHeader();
        $this->negotiateExtensions($request);
    }

    /**
     * Rejects unsupported extensions. With no supported extensions configured,
     * any `ext` parameter present is unsupported; registering a supported `ext`
     * lets a matching request pass.
     *
     * @throws MediaTypeUnsupported  when the Content-Type asserts an unsupported extension
     * @throws MediaTypeUnacceptable when the Accept header requests an unsupported extension
     */
    private function negotiateExtensions(JsonApiRequestInterface $request): void
    {
        foreach ($request->getAppliedExtensions() as $extension) {
            if (\in_array($extension, $this->supportedExtensions, true) === false) {
                throw new MediaTypeUnsupported($request->getHeaderLine('content-type'));
            }
        }

        foreach ($request->getRequestedExtensions() as $extension) {
            if (\in_array($extension, $this->supportedExtensions, true) === false) {
                throw new MediaTypeUnacceptable($request->getHeaderLine('accept'));
            }
        }
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
     * perform any additional JSON-schema linting.
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
