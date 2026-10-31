<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Components Object — the reusable component container the whole
 * document `$ref`s into.
 *
 * The document projection populates `schemas` (the per-type / envelope / named-enum
 * component set) and `securitySchemes`; the `responses`/`parameters`/`requestBodies`/
 * `headers` maps are populated by the path projection.
 * Each map is **omitted** from the serialized form when empty.
 */
final readonly class Components implements \JsonSerializable
{
    /**
     * @param array<string, Schema>                  $schemas
     * @param array<string, Response|Reference>      $responses
     * @param array<string, Parameter|Reference>     $parameters
     * @param array<string, RequestBody|Reference>   $requestBodies
     * @param array<string, Header|Reference>        $headers
     * @param array<string, SecurityScheme|Reference> $securitySchemes
     */
    public function __construct(
        public array $schemas = [],
        public array $responses = [],
        public array $parameters = [],
        public array $requestBodies = [],
        public array $headers = [],
        public array $securitySchemes = [],
    ) {}

    /**
     * Returns a copy with one schema component added/replaced.
     */
    public function withSchema(string $name, Schema $schema): self
    {
        $schemas = $this->schemas;
        $schemas[$name] = $schema;

        return new self($schemas, $this->responses, $this->parameters, $this->requestBodies, $this->headers, $this->securitySchemes);
    }

    /**
     * Returns a copy with one security-scheme component added/replaced.
     */
    public function withSecurityScheme(string $name, SecurityScheme|Reference $scheme): self
    {
        $schemes = $this->securitySchemes;
        $schemes[$name] = $scheme;

        return new self($this->schemas, $this->responses, $this->parameters, $this->requestBodies, $this->headers, $schemes);
    }

    /**
     * Returns a copy with one response component added/replaced.
     */
    public function withResponse(string $name, Response|Reference $response): self
    {
        $responses = $this->responses;
        $responses[$name] = $response;

        return new self($this->schemas, $responses, $this->parameters, $this->requestBodies, $this->headers, $this->securitySchemes);
    }

    /**
     * Returns a copy with one parameter component added/replaced.
     */
    public function withParameter(string $name, Parameter|Reference $parameter): self
    {
        $parameters = $this->parameters;
        $parameters[$name] = $parameter;

        return new self($this->schemas, $this->responses, $parameters, $this->requestBodies, $this->headers, $this->securitySchemes);
    }

    /**
     * Returns a copy with one request-body component added/replaced.
     */
    public function withRequestBody(string $name, RequestBody|Reference $requestBody): self
    {
        $requestBodies = $this->requestBodies;
        $requestBodies[$name] = $requestBody;

        return new self($this->schemas, $this->responses, $this->parameters, $requestBodies, $this->headers, $this->securitySchemes);
    }

    /**
     * Returns a copy with one header component added/replaced.
     */
    public function withHeader(string $name, Header|Reference $header): self
    {
        $headers = $this->headers;
        $headers[$name] = $header;

        return new self($this->schemas, $this->responses, $this->parameters, $this->requestBodies, $headers, $this->securitySchemes);
    }

    public function isEmpty(): bool
    {
        return $this->schemas === []
            && $this->responses === []
            && $this->parameters === []
            && $this->requestBodies === []
            && $this->headers === []
            && $this->securitySchemes === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        $this->mapInto($out, 'schemas', $this->schemas);
        $this->mapInto($out, 'responses', $this->responses);
        $this->mapInto($out, 'parameters', $this->parameters);
        $this->mapInto($out, 'requestBodies', $this->requestBodies);
        $this->mapInto($out, 'headers', $this->headers);
        $this->mapInto($out, 'securitySchemes', $this->securitySchemes);

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        $this->mapIntoJson($object, 'schemas', $this->schemas);
        $this->mapIntoJson($object, 'responses', $this->responses);
        $this->mapIntoJson($object, 'parameters', $this->parameters);
        $this->mapIntoJson($object, 'requestBodies', $this->requestBodies);
        $this->mapIntoJson($object, 'headers', $this->headers);
        $this->mapIntoJson($object, 'securitySchemes', $this->securitySchemes);

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }

    /**
     * @param array<string, mixed>                                                       $out
     * @param array<string, Schema|Response|Parameter|RequestBody|Header|SecurityScheme|Reference> $map
     */
    private function mapInto(array &$out, string $key, array $map): void
    {
        if ($map === []) {
            return;
        }
        $section = [];
        foreach ($map as $name => $component) {
            $section[$name] = $component->toArray();
        }
        $out[$key] = $section;
    }

    /**
     * @param array<string, Schema|Response|Parameter|RequestBody|Header|SecurityScheme|Reference> $map
     */
    private function mapIntoJson(\stdClass $object, string $key, array $map): void
    {
        if ($map === []) {
            return;
        }
        $section = new \stdClass();
        foreach ($map as $name => $component) {
            $section->{$name} = $component->toJson();
        }
        $object->{$key} = $section;
    }
}
