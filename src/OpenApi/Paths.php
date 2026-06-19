<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Paths Object — a map of path template (e.g. `/articles/{id}`) to
 * a {@see PathItem}.
 *
 * `paths` is **optional** in OAS 3.1 (a components-only / webhooks-only document is
 * valid), so the Slice-2 skeleton document leaves it absent; this VO exists for the
 * Slice-3 path projection. An empty Paths serializes as `{}`.
 */
final readonly class Paths implements \JsonSerializable
{
    /**
     * @param array<string, PathItem> $items path template → {@see PathItem}
     */
    public function __construct(
        public array $items = [],
    ) {}

    /**
     * Returns a copy with one path entry added/replaced.
     */
    public function with(string $path, PathItem $item): self
    {
        $items = $this->items;
        $items[$path] = $item;

        return new self($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->items as $path => $item) {
            $out[$path] = $item->toArray();
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        foreach ($this->items as $path => $item) {
            $object->{$path} = $item->toJson();
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
