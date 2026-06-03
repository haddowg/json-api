<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation;

/**
 * Supplies the JSON:API JSON Schemas the {@see DocumentValidator} validates
 * against, as decoded structures suitable for `opis/json-schema`.
 *
 * Two roots are exposed because request and response bodies differ: a request's
 * primary-data resource may omit its `id` (a client-generated resource) and may
 * carry a `lid`, whereas a response's resource requires `type` + `id`. Each
 * schema carries a stable `$id`; both are registered into the validator's
 * resolver so the request schema's cross-document `$ref`s into the base
 * (response) schema resolve.
 *
 * The returned objects are decoded JSON (`stdClass` trees), not `opis` `Schema`
 * instances — the validator compiles and caches them.
 *
 * @see https://jsonapi.org/format/1.1/#document-structure
 */
interface SchemaProviderInterface
{
    /**
     * The base/response JSON:API schema (resources require `type` + `id`).
     */
    public function responseSchema(): object;

    /**
     * The request JSON:API schema (resources may omit `id` and may carry `lid`).
     */
    public function requestSchema(): object;

    /**
     * The `$id` of the response schema, used to register it and to reference it
     * from the validator's composite root.
     */
    public function responseSchemaId(): string;

    /**
     * The `$id` of the request schema.
     */
    public function requestSchemaId(): string;
}
