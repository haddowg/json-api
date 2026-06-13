<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Data;

/**
 * A mutable in-memory store, keyed by JSON:API type then id, shared by the read
 * path and the write path so a create is immediately readable. A trivial stand-in
 * for a database — not a production query layer.
 *
 * Population is the seed fixtures' job ({@see \haddowg\JsonApi\Examples\MusicCatalog\Seed::into()}).
 */
final class InMemoryStore
{
    /**
     * @var array<string, array<string, object>> keyed by JSON:API type, then id
     */
    private array $items = [];

    /**
     * Every stored entity of a type, in insertion order ([] when the type is absent).
     *
     * @return list<object>
     */
    public function all(string $type): array
    {
        return \array_values($this->items[$type] ?? []);
    }

    public function find(string $type, string $id): ?object
    {
        return $this->items[$type][$id] ?? null;
    }

    public function put(string $type, string $id, object $entity): void
    {
        $this->items[$type][$id] = $entity;
    }

    public function remove(string $type, string $id): void
    {
        unset($this->items[$type][$id]);
    }

    public function has(string $type, string $id): bool
    {
        return isset($this->items[$type][$id]);
    }
}
