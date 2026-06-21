<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

use haddowg\JsonApi\Exception\LocalIdConflict;
use haddowg\JsonApi\Exception\LocalIdNotFound;

/**
 * The per-request map from a local id (`lid`) to the server id a freshly-created
 * resource received, scoped by `type`.
 *
 * Atomic Operations lets an operation reference a resource created earlier in the
 * same batch by `lid` rather than by `id`. As each `add` completes, the executor
 * registers the `(type, lid)` → assigned-`id` mapping here; a later operation that
 * references the `lid` resolves it back to the real id. The registry is a pure map
 * — it neither parses nor persists; the {@see AtomicLoop} threads a single fresh
 * instance through every operation of one batch.
 *
 * @see https://jsonapi.org/ext/atomic/#operation-objects
 */
final class LocalIdRegistry
{
    /**
     * @var array<string, array<string, string>> keyed by `type` then `lid`
     */
    private array $map = [];

    /**
     * Records the server id a `(type, lid)` pair resolves to.
     *
     * @throws LocalIdConflict when the `(type, lid)` pair is already registered
     */
    public function register(string $type, string $lid, string $id): void
    {
        if (isset($this->map[$type][$lid])) {
            throw new LocalIdConflict($type, $lid);
        }

        $this->map[$type][$lid] = $id;
    }

    /**
     * Resolves a `(type, lid)` pair to its registered server id.
     *
     * @throws LocalIdNotFound when the `(type, lid)` pair has not been registered
     */
    public function resolve(string $type, string $lid): string
    {
        return $this->map[$type][$lid] ?? throw new LocalIdNotFound($type, $lid);
    }

    /**
     * Whether a `(type, lid)` pair has been registered.
     */
    public function has(string $type, string $lid): bool
    {
        return isset($this->map[$type][$lid]);
    }
}
