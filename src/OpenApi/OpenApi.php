<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The root OpenAPI 3.1 document object.
 *
 * `openapi` (pinned to `3.1.0`) and `info` are required; the OAS meta-schema also
 * requires **at least one** of `paths`/`components`/`webhooks`, which the Slice-2
 * skeleton satisfies by always emitting `components` (it is path-less until the
 * Slice-3 path projection).
 *
 * The root carries **no** JSON:API `jsonapi` member — that object is a *component
 * schema* (`#/components/schemas/JsonApi`) referenced by the document envelopes,
 * not an OAS root member. {@see toArray()} is the assertion-friendly form;
 * {@see toJson()} / {@see toJsonString()} produce the wire document.
 */
final readonly class OpenApi implements \JsonSerializable
{
    public const VERSION = '3.1.0';

    /**
     * @param list<Server>               $servers
     * @param list<SecurityRequirement>  $security the document-level default (OR-ed alternatives)
     * @param list<Tag>                  $tags     the document-root tag definitions
     */
    public function __construct(
        public Info $info,
        public Components $components,
        public string $openapi = self::VERSION,
        public ?Paths $paths = null,
        public array $servers = [],
        public array $security = [],
        public array $tags = [],
        public ?ExternalDocumentation $externalDocs = null,
        public ?string $jsonSchemaDialect = null,
    ) {}

    public function withInfo(Info $info): self
    {
        return new self($info, $this->components, $this->openapi, $this->paths, $this->servers, $this->security, $this->tags, $this->externalDocs, $this->jsonSchemaDialect);
    }

    public function withComponents(Components $components): self
    {
        return new self($this->info, $components, $this->openapi, $this->paths, $this->servers, $this->security, $this->tags, $this->externalDocs, $this->jsonSchemaDialect);
    }

    public function withPaths(Paths $paths): self
    {
        return new self($this->info, $this->components, $this->openapi, $paths, $this->servers, $this->security, $this->tags, $this->externalDocs, $this->jsonSchemaDialect);
    }

    /**
     * @param list<Server> $servers
     */
    public function withServers(array $servers): self
    {
        return new self($this->info, $this->components, $this->openapi, $this->paths, \array_values($servers), $this->security, $this->tags, $this->externalDocs, $this->jsonSchemaDialect);
    }

    /**
     * @param list<Tag> $tags
     */
    public function withTags(array $tags): self
    {
        return new self($this->info, $this->components, $this->openapi, $this->paths, $this->servers, $this->security, \array_values($tags), $this->externalDocs, $this->jsonSchemaDialect);
    }

    /**
     * @param list<SecurityRequirement> $security
     */
    public function withSecurity(array $security): self
    {
        return new self($this->info, $this->components, $this->openapi, $this->paths, $this->servers, \array_values($security), $this->tags, $this->externalDocs, $this->jsonSchemaDialect);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'openapi' => $this->openapi,
            'info' => $this->info->toArray(),
        ];
        if ($this->jsonSchemaDialect !== null) {
            $out['jsonSchemaDialect'] = $this->jsonSchemaDialect;
        }
        if ($this->servers !== []) {
            $out['servers'] = \array_map(static fn(Server $s): array => $s->toArray(), $this->servers);
        }
        if ($this->paths !== null && !$this->paths->isEmpty()) {
            $out['paths'] = $this->paths->toArray();
        }
        $out['components'] = $this->components->toArray();
        if ($this->security !== []) {
            $out['security'] = \array_map(static fn(SecurityRequirement $r): array => $r->toArray(), $this->security);
        }
        if ($this->tags !== []) {
            $out['tags'] = \array_map(static fn(Tag $t): array => $t->toArray(), $this->tags);
        }
        if ($this->externalDocs !== null) {
            $out['externalDocs'] = $this->externalDocs->toArray();
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        $object->openapi = $this->openapi;
        $object->info = $this->info->toJson();
        if ($this->jsonSchemaDialect !== null) {
            $object->jsonSchemaDialect = $this->jsonSchemaDialect;
        }
        if ($this->servers !== []) {
            $object->servers = \array_map(static fn(Server $s): \stdClass => $s->toJson(), $this->servers);
        }
        if ($this->paths !== null && !$this->paths->isEmpty()) {
            $object->paths = $this->paths->toJson();
        }
        $object->components = $this->components->toJson();
        if ($this->security !== []) {
            $object->security = \array_map(static fn(SecurityRequirement $r): \stdClass => $r->toJson(), $this->security);
        }
        if ($this->tags !== []) {
            $object->tags = \array_map(static fn(Tag $t): \stdClass => $t->toJson(), $this->tags);
        }
        if ($this->externalDocs !== null) {
            $object->externalDocs = $this->externalDocs->toJson();
        }

        return $object;
    }

    /**
     * The document as a JSON string (the controller / CLI / file artifact form).
     *
     * @throws \JsonException
     */
    public function toJsonString(bool $pretty = false): string
    {
        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        return \json_encode($this->toJson(), $flags);
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
