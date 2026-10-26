<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\MediaType;

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
     * By default the `Content-Type` check only polices the parameters of a JSON:API
     * media type, so an absent header or a different media type passes. Pass
     * `$strictContentType` as `true` on a request that must carry a JSON:API document
     * (a write, or a custom action whose input is a document or nothing): the header
     * must then be present and be `application/vnd.api+json` with only `ext`/`profile`
     * parameters, or the request is a `415`.
     *
     * Pass `$requireJsonApiContentType` as `false` to skip the request-body
     * `Content-Type` assertion entirely (Accept negotiation and extension support are
     * unchanged) — for endpoints that accept a non-JSON:API request body, such as a
     * custom action declaring a raw/multipart input. Check such a body's media type
     * with {@see negotiateContentType()} instead. `$strictContentType` has no effect
     * when the assertion is skipped.
     *
     * @throws MediaTypeUnsupported
     * @throws MediaTypeUnacceptable
     */
    public function negotiate(
        JsonApiRequestInterface $request,
        bool $requireJsonApiContentType = true,
        bool $strictContentType = false,
    ): void {
        if ($requireJsonApiContentType) {
            if ($strictContentType && !MediaType::isJsonApi($request->getHeaderLine('content-type'))) {
                throw new MediaTypeUnsupported($request->getHeaderLine('content-type'));
            }
            $request->validateContentTypeHeader();
        }
        $request->validateAcceptHeader();
        $this->negotiateExtensions($request);
    }

    /**
     * Matches the request `Content-Type` against the media types an endpoint accepts,
     * such as a custom action taking a raw body, and returns the match.
     *
     * It is a `415` when the header is absent, names more than one media type, or
     * matches none of `$accepted`. It is also a `415` when the request's media type is
     * one a browser sends cross-origin without a preflight
     * ({@see ContentTypeMatcher::CORS_SAFELISTED}) and `$accepted` only reaches it through
     * a wildcard: an endpoint takes `text/plain`, `multipart/form-data` or
     * `application/x-www-form-urlencoded` only by naming it. A JSON:API media type that
     * matches is held to the usual parameter rule.
     *
     * Run {@see negotiate()} with `$requireJsonApiContentType` as `false` alongside it, so
     * `Accept` and extension support are still negotiated.
     *
     * @param list<string> $accepted the accepted media types; wildcards such as `image/*` allowed
     *
     * @throws MediaTypeUnsupported
     */
    public function negotiateContentType(JsonApiRequestInterface $request, array $accepted): ContentTypeMatch
    {
        $contentType = $request->getHeaderLine('content-type');
        $match = ContentTypeMatcher::match($contentType, $accepted);

        if ($match === null
            || ($match->corsSafelisted && !$match->isExplicit())
            || ($match->mediaType === 'application/vnd.api+json' && !MediaType::isJsonApi($contentType))
        ) {
            throw new MediaTypeUnsupported($contentType);
        }

        return $match;
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
