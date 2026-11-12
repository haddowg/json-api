<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says which documents the server checks beyond what parsing and
 * hydration already refuse, so the request and response body codes are offered only
 * where something raises them.
 *
 * A separate, opt-in contract rather than methods on {@see ServerMetadataInterface}, the
 * same shape as {@see DeclaresReadOnlyAttributeRejection}. Metadata that doesn't implement
 * it offers `REQUEST_BODY_INVALID_JSON_API` on every operation reading a document and the
 * `RESPONSE_BODY_INVALID_*` codes on every operation, as returning `true` from each method
 * does.
 */
interface DeclaresDocumentValidation extends ServerMetadataInterface
{
    /**
     * Whether every request document is validated against the JSON:API schema, as
     * {@see \haddowg\JsonApi\Middleware\RequestValidationMiddleware} with a
     * {@see \haddowg\JsonApi\Validation\DocumentValidator} does, refusing a bad one with
     * `REQUEST_BODY_INVALID_JSON_API`.
     */
    public function validatesRequestDocuments(): bool;

    /**
     * Whether a create or update body whose `attributes`, `relationships` or `meta`
     * member, or one of whose relationships, is not an object is refused with
     * `REQUEST_BODY_INVALID_JSON_API` even when request documents are not validated.
     */
    public function checksResourceObjectMembers(): bool;

    /**
     * Whether a response is validated before it is sent, as
     * {@see \haddowg\JsonApi\Middleware\ResponseValidationMiddleware} does when it throws
     * on a violation, answering a bad one with `RESPONSE_BODY_INVALID_JSON` or
     * `RESPONSE_BODY_INVALID_JSON_API`. Report `false` for a validator that only logs.
     */
    public function validatesResponseDocuments(): bool;
}
