<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Exception\ResponseBodyInvalidJsonApi;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Validates a decoded JSON:API document against the JSON:API JSON Schema,
 * optionally augmented by additional schemas (profile fragments and
 * per-resource compiled schemas).
 *
 * Backed by `opis/json-schema` (an optional dependency — constructing this class
 * fails fast if the package is absent). The schemas from the {@see SchemaProvider}
 * are registered into one reusable `opis` {@see Validator} at construction;
 * `opis` compiles and caches each schema on first use, so validation is cheap
 * across requests.
 *
 * Composition is `allOf`: every validation builds a synthetic composite root
 * `{ "allOf": [ {"$ref": <base/request id>}, …additional ], "unevaluatedProperties": false }`
 * and validates the document against it. The base schema's document-root
 * `unevaluatedProperties` has been relocated onto this composite by the provider,
 * so an additional schema's top-level `properties` extend (rather than collide
 * with) the permitted members.
 *
 * A failed validation throws a typed {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * — {@see RequestBodyInvalidJsonApi} (400) or {@see ResponseBodyInvalidJsonApi}
 * (500) — carrying one violation per `opis` leaf error, each with the JSON
 * Pointer (`source.pointer`) of the offending location. The existing
 * error-handler middleware renders these for free.
 *
 * @see https://jsonapi.org/format/1.1/#document-structure
 */
final class DocumentValidator
{
    private readonly Validator $validator;

    public function __construct(private readonly \haddowg\JsonApi\Validation\SchemaProviderInterface $schemaProvider)
    {
        $validator = new Validator();
        // Raise the error cap so an invalid document surfaces several violations at
        // once rather than only the first (the formatter walks the sub-error tree
        // for per-pointer messages). Kept to setMaxErrors(), which exists across all
        // supported opis/json-schema 2.x; the later setStopAtFirstError() toggle is
        // intentionally not used so behaviour is uniform on the lowest pinned version.
        $validator->setMaxErrors(20);

        $resolver = $validator->resolver();
        if ($resolver === null) {
            throw new \RuntimeException('The opis/json-schema validator has no schema resolver.');
        }

        $resolver->registerRaw($schemaProvider->responseSchema(), $schemaProvider->responseSchemaId());
        $resolver->registerRaw($schemaProvider->requestSchema(), $schemaProvider->requestSchemaId());

        $this->validator = $validator;
    }

    /**
     * Validates a request body. A client-generated resource may omit its `id`
     * and may carry a `lid`.
     *
     * @param list<object> $additionalSchemas profile fragments / per-resource schemas to compose
     *
     * @throws RequestBodyInvalidJsonApi when the document is not a valid JSON:API request
     */
    public function validateRequest(mixed $document, array $additionalSchemas = []): void
    {
        $violations = $this->validate($this->schemaProvider->requestSchemaId(), $additionalSchemas, $document);

        if ($violations !== []) {
            throw new RequestBodyInvalidJsonApi($violations, $document);
        }
    }

    /**
     * Validates a response body against the base (response) schema.
     *
     * @param list<object> $additionalSchemas profile fragments / per-resource schemas to compose
     *
     * @throws ResponseBodyInvalidJsonApi when the document is not a valid JSON:API response
     */
    public function validateResponse(mixed $document, array $additionalSchemas = []): void
    {
        $violations = $this->validate($this->schemaProvider->responseSchemaId(), $additionalSchemas, $document);

        if ($violations !== []) {
            throw new ResponseBodyInvalidJsonApi($violations, $document);
        }
    }

    /**
     * Runs the document through the composite schema and maps each `opis` leaf
     * error to the `{message, property?}` shape the typed exceptions consume.
     *
     * @param list<object> $additionalSchemas
     *
     * @return list<array{message: string, property?: string}>
     */
    private function validate(string $rootId, array $additionalSchemas, mixed $document): array
    {
        $result = $this->validator->validate(
            Helper::toJSON($document),
            $this->composite($rootId, $additionalSchemas),
        );

        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $violations = [];

        foreach ((new ErrorFormatter())->formatKeyed($error) as $pointer => $messages) {
            if (!\is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (!\is_string($message)) {
                    continue;
                }

                $violation = ['message' => $message];

                $property = (string) $pointer;
                if ($property !== '') {
                    $violation['property'] = $property;
                }

                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Builds the synthetic composite root: the base/request schema (by `$ref`)
     * plus any additional schemas, all under one `allOf`, with the relocated
     * top-level `unevaluatedProperties`. Its `$id` is content-derived so `opis`
     * caches the compiled form (the common no-fragment case reuses one schema).
     *
     * @param list<object> $additionalSchemas
     */
    private function composite(string $rootId, array $additionalSchemas): object
    {
        $allOf = [(object) ['$ref' => $rootId]];
        foreach ($additionalSchemas as $schema) {
            $allOf[] = $schema;
        }

        return (object) [
            '$id' => 'urn:haddowg:jsonapi:composite:' . \hash('xxh128', \serialize([$rootId, $additionalSchemas])),
            'type' => 'object',
            'allOf' => $allOf,
            'unevaluatedProperties' => false,
        ];
    }
}
