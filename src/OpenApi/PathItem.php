<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Path Item Object — the operations available on a single path, one
 * {@see Operation} per HTTP method, plus path-level `parameters` shared by all of
 * them.
 *
 * Modelled now (Slice 2) but only populated by the path/operation projection
 * (Slice 3).
 */
final readonly class PathItem implements \JsonSerializable
{
    /**
     * @param array<string, Operation>  $operations lower-cased HTTP method → {@see Operation} (`get`/`put`/`post`/`delete`/`options`/`head`/`patch`/`trace`)
     * @param list<Parameter|Reference> $parameters path-level parameters common to every operation
     */
    public function __construct(
        public array $operations = [],
        public ?string $summary = null,
        public ?string $description = null,
        public array $parameters = [],
    ) {}

    /**
     * The HTTP methods a Path Item may carry, in OAS emit order.
     *
     * @var list<string>
     */
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Returns a copy with the given HTTP method's operation added/replaced.
     */
    public function withOperation(string $method, Operation $operation): self
    {
        $operations = $this->operations;
        $operations[\strtolower($method)] = $operation;

        return new self($operations, $this->summary, $this->description, $this->parameters);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->parameters !== []) {
            $out['parameters'] = \array_map(static fn(Parameter|Reference $p): array => $p->toArray(), $this->parameters);
        }
        foreach (self::METHODS as $method) {
            if (isset($this->operations[$method])) {
                $out[$method] = $this->operations[$method]->toArray();
            }
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        if ($this->summary !== null) {
            $object->summary = $this->summary;
        }
        if ($this->description !== null) {
            $object->description = $this->description;
        }
        if ($this->parameters !== []) {
            $object->parameters = \array_map(static fn(Parameter|Reference $p): \stdClass => $p->toJson(), $this->parameters);
        }
        foreach (self::METHODS as $method) {
            if (isset($this->operations[$method])) {
                $object->{$method} = $this->operations[$method]->toJson();
            }
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
