<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * A class that supplies the JSON Schema of a raw (non-JSON:API) response body, named by
 * a {@see RawResult}. The projector emits it once as a component under
 * `#/components/schemas/<short class name>` and every raw result naming the class
 * `$ref`s it, where an inline schema array would be repeated at each use.
 *
 * The method is static because a {@see RawResult} names the class in attribute
 * arguments, and the projector needs the schema without constructing anything.
 */
interface DescribesRawBody
{
    /**
     * The JSON Schema (draft 2020-12, as OpenAPI 3.1 speaks it) of the body.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array;
}
