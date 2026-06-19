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
 * when absent/empty.
 */
final readonly class Operation implements \JsonSerializable
{
    /**
     * @param list<string>                       $tags
     * @param list<Parameter|Reference>          $parameters
     * @param list<SecurityRequirement>          $security  the OR-ed alternatives ([] = inherit document default; an explicit empty requirement makes auth optional)
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
    ) {}

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

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
