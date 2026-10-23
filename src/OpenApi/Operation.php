<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Operation Object — a single HTTP operation on a path.
 *
 * Modelled in full now (Slice 2) but only **populated** by the path/operation
 * projection (Slice 3); the document skeleton this slice assembles carries no
 * paths. `tags` (group membership for the doc renderers — §4.7) is first-class here
 * because the contract resolves per-operation tag refs.
 *
 * `responses` is the one required member; everything else is optional and omitted
 * when absent/empty. Vendor extensions (`x-…`) are emitted after the standard
 * members.
 */
final readonly class Operation implements \JsonSerializable
{
    /**
     * @param list<string>                       $tags
     * @param list<Parameter|Reference>          $parameters
     * @param list<SecurityRequirement>|null     $security  null omits the field (inherit the document default); `[]` emits `security: []` — the OAS operation-level public override (no auth); a non-empty list is the OR-ed alternatives
     * @param array<string, mixed>               $extensions vendor extensions, each key `x-…`
     */
    public function __construct(
        public Responses $responses,
        public array $tags = [],
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $operationId = null,
        public ?ExternalDocumentation $externalDocs = null,
        public array $parameters = [],
        public RequestBody|Reference|null $requestBody = null,
        public ?array $security = null,
        public ?bool $deprecated = null,
        private array $extensions = [],
    ) {
        VendorExtensions::assertValid($extensions);
    }

    /**
     * Sets a vendor extension. The name is normalised to the `x-` prefix, so
     * `withExtension('feature', 'agency')` emits `x-feature: agency`.
     *
     * @throws \InvalidArgumentException for a reserved `x-oai-`/`x-oas-` name
     */
    public function withExtension(string $name, mixed $value): self
    {
        return $this->withExtensions([$name => $value]);
    }

    /**
     * Merges several vendor extensions over the existing ones, each name normalised
     * as {@see withExtension()} does.
     *
     * @param array<string, mixed> $extensions
     *
     * @throws \InvalidArgumentException for a reserved `x-oai-`/`x-oas-` name
     */
    public function withExtensions(array $extensions): self
    {
        return new self(
            $this->responses,
            $this->tags,
            $this->summary,
            $this->description,
            $this->operationId,
            $this->externalDocs,
            $this->parameters,
            $this->requestBody,
            $this->security,
            $this->deprecated,
            [...$this->extensions, ...VendorExtensions::normalise($extensions)],
        );
    }

    /**
     * Reads a vendor extension, or `null` when absent. The name is normalised to
     * the `x-` prefix.
     */
    public function extension(string $name): mixed
    {
        return $this->extensions[\str_starts_with($name, 'x-') ? $name : 'x-' . $name] ?? null;
    }

    /**
     * Every vendor extension, keyed `x-…`.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    public function withResponses(Responses $responses): self
    {
        return new self($responses, $this->tags, $this->summary, $this->description, $this->operationId, $this->externalDocs, $this->parameters, $this->requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    /**
     * Returns a copy with one status-code (or `default`) response added or replaced.
     */
    public function withResponse(string $status, Response|Reference $response): self
    {
        return $this->withResponses($this->responses->with($status, $response));
    }

    /**
     * @param list<string> $tags
     */
    public function withTags(array $tags): self
    {
        return new self($this->responses, \array_values($tags), $this->summary, $this->description, $this->operationId, $this->externalDocs, $this->parameters, $this->requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    public function withSummary(?string $summary): self
    {
        return new self($this->responses, $this->tags, $summary, $this->description, $this->operationId, $this->externalDocs, $this->parameters, $this->requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    public function withDescription(?string $description): self
    {
        return new self($this->responses, $this->tags, $this->summary, $description, $this->operationId, $this->externalDocs, $this->parameters, $this->requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    public function withOperationId(?string $operationId): self
    {
        return new self($this->responses, $this->tags, $this->summary, $this->description, $operationId, $this->externalDocs, $this->parameters, $this->requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    public function withExternalDocs(?ExternalDocumentation $externalDocs): self
    {
        return new self($this->responses, $this->tags, $this->summary, $this->description, $this->operationId, $externalDocs, $this->parameters, $this->requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    /**
     * @param list<Parameter|Reference> $parameters
     */
    public function withParameters(array $parameters): self
    {
        return new self($this->responses, $this->tags, $this->summary, $this->description, $this->operationId, $this->externalDocs, \array_values($parameters), $this->requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    /**
     * Returns a copy with one parameter added. A {@see Parameter} replaces the one
     * already there with the same `name` and `in`, keeping its position; a
     * {@see Reference} is appended.
     */
    public function withParameter(Parameter|Reference $parameter): self
    {
        return $this->withParameters(self::replaceOrAppend($this->parameters, $parameter));
    }

    public function withRequestBody(RequestBody|Reference|null $requestBody): self
    {
        return new self($this->responses, $this->tags, $this->summary, $this->description, $this->operationId, $this->externalDocs, $this->parameters, $requestBody, $this->security, $this->deprecated, $this->extensions);
    }

    /**
     * @param list<SecurityRequirement>|null $security null inherits the document default; `[]` marks the operation public
     */
    public function withSecurity(?array $security): self
    {
        return new self($this->responses, $this->tags, $this->summary, $this->description, $this->operationId, $this->externalDocs, $this->parameters, $this->requestBody, $security === null ? null : \array_values($security), $this->deprecated, $this->extensions);
    }

    public function withDeprecated(?bool $deprecated): self
    {
        return new self($this->responses, $this->tags, $this->summary, $this->description, $this->operationId, $this->externalDocs, $this->parameters, $this->requestBody, $this->security, $deprecated, $this->extensions);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->tags !== []) {
            $out['tags'] = \array_values($this->tags);
        }
        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->externalDocs !== null) {
            $out['externalDocs'] = $this->externalDocs->toArray();
        }
        if ($this->operationId !== null) {
            $out['operationId'] = $this->operationId;
        }
        if ($this->parameters !== []) {
            $out['parameters'] = \array_map(static fn(Parameter|Reference $p): array => $p->toArray(), $this->parameters);
        }
        if ($this->requestBody !== null) {
            $out['requestBody'] = $this->requestBody->toArray();
        }
        $out['responses'] = $this->responses->toArray();
        if ($this->deprecated !== null) {
            $out['deprecated'] = $this->deprecated;
        }
        if ($this->security !== null) {
            $out['security'] = \array_map(static fn(SecurityRequirement $r): array => $r->toArray(), $this->security);
        }
        foreach ($this->extensions as $key => $value) {
            $out[$key] = $value;
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        if ($this->tags !== []) {
            $object->tags = \array_values($this->tags);
        }
        if ($this->summary !== null) {
            $object->summary = $this->summary;
        }
        if ($this->description !== null) {
            $object->description = $this->description;
        }
        if ($this->externalDocs !== null) {
            $object->externalDocs = $this->externalDocs->toJson();
        }
        if ($this->operationId !== null) {
            $object->operationId = $this->operationId;
        }
        if ($this->parameters !== []) {
            $object->parameters = \array_map(static fn(Parameter|Reference $p): \stdClass => $p->toJson(), $this->parameters);
        }
        if ($this->requestBody !== null) {
            $object->requestBody = $this->requestBody->toJson();
        }
        $object->responses = $this->responses->toJson();
        if ($this->deprecated !== null) {
            $object->deprecated = $this->deprecated;
        }
        if ($this->security !== null) {
            $object->security = \array_map(static fn(SecurityRequirement $r): \stdClass => $r->toJson(), $this->security);
        }
        foreach ($this->extensions as $key => $value) {
            $object->{$key} = Serialization::convert($value);
        }

        return $object;
    }

    /**
     * Replaces the parameter with the same `name` and `in` in place, or appends.
     * A reference has no name to match, so it is always appended.
     *
     * @param list<Parameter|Reference> $parameters
     *
     * @return list<Parameter|Reference>
     */
    private static function replaceOrAppend(array $parameters, Parameter|Reference $parameter): array
    {
        if ($parameter instanceof Parameter) {
            foreach ($parameters as $i => $existing) {
                if ($existing instanceof Parameter && $existing->name === $parameter->name && $existing->in === $parameter->in) {
                    $parameters[$i] = $parameter;

                    return \array_values($parameters);
                }
            }
        }
        $parameters[] = $parameter;

        return $parameters;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
