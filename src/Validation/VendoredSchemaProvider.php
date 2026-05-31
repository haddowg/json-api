<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation;

/**
 * The default {@see SchemaProvider}: loads the JSON:API 1.1 schemas vendored
 * under `resources/schemas/` and decodes them once (memoized per instance).
 *
 * The response schema is the byte-faithful upstream file; this provider applies
 * the single transformation the validator needs — stripping the document-**root**
 * `unevaluatedProperties` keyword — so that {@see DocumentValidator} can re-apply
 * it on its `allOf` composite instead. That relocation is what lets a profile's
 * schema fragment extend the set of permitted top-level members (a fragment's
 * top-level `properties` are seen by the composite's `unevaluatedProperties`,
 * which they would not be by the base schema's own root keyword). Nested
 * `unevaluatedProperties` are left intact.
 *
 * @see resources/schemas/README.md for the upstream source and refresh procedure.
 */
final class VendoredSchemaProvider implements SchemaProvider
{
    private const string RESPONSE_ID = 'https://jsonapi.org/schemas/spec/v1.1/draft';

    private const string REQUEST_ID = 'https://jsonapi.org/schemas/spec/v1.1/request';

    private readonly string $schemaDir;

    private ?object $responseSchema = null;

    private ?object $requestSchema = null;

    public function __construct(?string $schemaDir = null)
    {
        $this->schemaDir = $schemaDir ?? \dirname(__DIR__, 2) . '/resources/schemas';
    }

    public function responseSchema(): object
    {
        if ($this->responseSchema === null) {
            $schema = $this->load('jsonapi-1.1.json');
            // Relocated onto the validator's composite root (see class docblock).
            unset($schema->unevaluatedProperties);
            $this->responseSchema = $schema;
        }

        return $this->responseSchema;
    }

    public function requestSchema(): object
    {
        return $this->requestSchema ??= $this->load('jsonapi-1.1-request.json');
    }

    public function responseSchemaId(): string
    {
        return self::RESPONSE_ID;
    }

    public function requestSchemaId(): string
    {
        return self::REQUEST_ID;
    }

    private function load(string $file): object
    {
        $path = $this->schemaDir . '/' . $file;

        $contents = \file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read JSON:API schema file: {$path}");
        }

        $decoded = \json_decode($contents, false, 512, \JSON_THROW_ON_ERROR);
        if (!\is_object($decoded)) {
            throw new \RuntimeException("JSON:API schema file is not a JSON object: {$path}");
        }

        return $decoded;
    }
}
