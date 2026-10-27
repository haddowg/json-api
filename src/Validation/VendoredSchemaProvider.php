<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation;

/**
 * The default {@see SchemaProvider}: loads the JSON:API 1.1 schemas vendored
 * under `resources/schemas/` and decodes them once (memoized per instance).
 *
 * The response schema is the byte-faithful upstream file; this provider applies
 * five transformations to it at load time:
 *
 * - It strips the document-**root** `unevaluatedProperties` keyword so that
 *   {@see DocumentValidator} can re-apply it on its `allOf` composite instead.
 *   That relocation is what lets a profile's schema fragment extend the set of
 *   permitted top-level members (a fragment's top-level `properties` are seen by
 *   the composite's `unevaluatedProperties`, which they would not be by the base
 *   schema's own root keyword).
 * - It relaxes `definitions.linkUrl` from `format: uri` to `format: uri-reference`.
 *   JSON:API 1.1 lets a link be any URI-reference (RFC 3986 §4.1), so a
 *   host-relative `/albums?page[number]=2` is valid, while `uri` demands an
 *   absolute URI. The request schema reaches links only through this definition.
 * - It lets `definitions.resourceLinks` carry members beyond `self`, each a link.
 *   JSON:API 1.1 says a resource's links object MAY contain `self`; it forbids no
 *   other member, and declared and action links render more.
 * - It lets `definitions.topLevelLinks` carry members beyond `self`, `related`,
 *   `describedby` and the pagination links, each a link. JSON:API 1.1 lists what
 *   that object MAY contain without making the list exclusive, and a custom action
 *   answering with a URL renders a `location` link there.
 * - It forbids a `links` or `relationships` member inside an attribute value, at
 *   any depth. JSON:API 1.1 says no object that is or sits inside an attribute
 *   value may contain either member, and upstream lets any value through. A
 *   recursive `definitions.attributeValue` closes both names on every nested
 *   object, including objects inside arrays, and `definitions.attributes` applies
 *   it to each attribute's value through `patternProperties` keyed on the
 *   `memberName` pattern, so `@`-members and the `type`/`id` rule are untouched.
 *   A top-level attribute called `links` is still allowed. The request schema
 *   reaches attributes only through this definition.
 *
 * @see resources/schemas/README.md for the upstream source and refresh procedure.
 */
final class VendoredSchemaProvider implements \haddowg\JsonApi\Validation\SchemaProviderInterface
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
            // A link may be any URI-reference (see class docblock).
            $definitions = \get_object_vars($schema)['definitions'] ?? null;
            $linkUrl = \is_object($definitions) ? (\get_object_vars($definitions)['linkUrl'] ?? null) : null;
            if ($linkUrl instanceof \stdClass) {
                $linkUrl->format = 'uri-reference';
            }
            // A resource's links object may carry any named link (see class docblock).
            $resourceLinks = \is_object($definitions) ? (\get_object_vars($definitions)['resourceLinks'] ?? null) : null;
            if ($resourceLinks instanceof \stdClass) {
                $resourceLinks->unevaluatedProperties = (object) ['$ref' => '#/definitions/link'];
            }
            // So may the top-level links object (see class docblock).
            $topLevelLinks = \is_object($definitions) ? (\get_object_vars($definitions)['topLevelLinks'] ?? null) : null;
            if ($topLevelLinks instanceof \stdClass) {
                $topLevelLinks->unevaluatedProperties = (object) ['$ref' => '#/definitions/link'];
            }
            // No object inside an attribute value may hold `links` or `relationships` (see class docblock).
            if ($definitions instanceof \stdClass) {
                $this->reserveAttributeValueMembers($definitions);
            }
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

    private function reserveAttributeValueMembers(\stdClass $definitions): void
    {
        $attributes = $definitions->attributes ?? null;
        $memberName = $definitions->memberName ?? null;
        $patterns = $memberName instanceof \stdClass ? ($memberName->patternProperties ?? null) : null;
        if (!$attributes instanceof \stdClass || !$patterns instanceof \stdClass) {
            return;
        }

        $self = (object) ['$ref' => '#/definitions/attributeValue'];
        $definitions->attributeValue = (object) [
            'description' => 'An attribute value. No object in it, at any depth, may contain a `links` or `relationships` member.',
            'properties' => (object) ['links' => false, 'relationships' => false],
            'additionalProperties' => $self,
            'items' => $self,
        ];

        $valueSchemas = new \stdClass();
        foreach (\array_keys(\get_object_vars($patterns)) as $pattern) {
            $valueSchemas->{$pattern} = $self;
        }
        $attributes->patternProperties = $valueSchemas;
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
