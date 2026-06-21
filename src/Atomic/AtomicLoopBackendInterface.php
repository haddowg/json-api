<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

/**
 * The storage- and framework-specific backend the {@see AtomicLoop} drives to run
 * one atomic batch all-or-nothing.
 *
 * Core owns the loop (begin, iterate in order, commit on success / rollback on
 * failure) but knows nothing about transactions or persistence; an implementation
 * — the Symfony bundle's Doctrine-backed executor — supplies that. {@see begin()}
 * opens a transaction; {@see executeOne()} applies one operation against the open
 * transaction, registering any created resource's `lid` in the shared
 * {@see LocalIdRegistry} and resolving referenced `lid`s through it; {@see commit()}
 * persists the batch; {@see rollback()} discards it.
 *
 * The same {@see LocalIdRegistry} instance is passed to every {@see executeOne()}
 * call of one batch, so a later operation can reference a resource an earlier one
 * created.
 */
interface AtomicLoopBackendInterface
{
    /**
     * Opens the all-or-nothing boundary (a storage transaction) for the batch.
     */
    public function begin(): void;

    /**
     * Commits the batch's transaction.
     *
     * The implementation MUST also drain any deferred post-commit hooks the
     * operations queued (dispatching them only now the data is durable) — a bundle
     * concern; core only states the contract.
     */
    public function commit(): void;

    /**
     * Rolls the batch's transaction back, discarding every applied change.
     *
     * The implementation MUST also discard any deferred post-commit hooks the
     * operations queued (they must never fire for a rolled-back batch) — a bundle
     * concern; core only states the contract.
     */
    public function rollback(): void;

    /**
     * Applies one operation against the open transaction and returns its result
     * fragment.
     *
     * Implementations register a created resource's `(type, lid)` → assigned id in
     * `$lids`, and resolve any `lid` the operation references through the same
     * registry — which is the identical instance across every call of one batch. A
     * structural or semantic failure throws a
     * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}; the loop catches
     * it, rolls back, and renders the error (prefixing its pointers with the
     * operation index). Any other {@see \Throwable} is left to propagate.
     */
    public function executeOne(OperationDescriptor $op, LocalIdRegistry $lids): AtomicResult;
}
