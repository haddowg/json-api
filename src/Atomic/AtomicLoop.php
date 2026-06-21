<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * The framework-agnostic all-or-nothing driver for an Atomic Operations batch.
 *
 * Given the parsed {@see OperationDescriptor}s and a storage-specific
 * {@see AtomicLoopBackendInterface}, it opens the transaction, runs the operations
 * **in order** (threading one shared {@see LocalIdRegistry} so a later operation can
 * reference a resource an earlier one created), and either commits and returns an
 * {@see AtomicResultsResponse} of every result fragment, or — on the first operation
 * (or the commit) that throws a {@see JsonApiExceptionInterface} — rolls back and
 * returns a single {@see ErrorResponse} whose errors are prefixed with the failing
 * operation's index.
 *
 * The error response advertises the Atomic Operations extension on its
 * `Content-Type` `ext` parameter ({@see AbstractResponse::withExtensions()}): the
 * failure document is produced under the applied extension, so it must declare it,
 * just as the success path does.
 *
 * The prefixing rule: a failing operation at index `i` whose inner error already
 * carries a `source.pointer` such as `/data/attributes/title` is reported at
 * `/atomic:operations/<i>/data/attributes/title`; an inner error with no pointer is
 * reported at `/atomic:operations/<i>`. A non-JSON:API {@see \Throwable} is left to
 * propagate after a rollback (the kernel maps it).
 */
final class AtomicLoop
{
    /**
     * @param list<OperationDescriptor> $ops
     */
    public function run(array $ops, AtomicLoopBackendInterface $backend): AtomicResultsResponse|ErrorResponse
    {
        $lids = new LocalIdRegistry();

        $backend->begin();

        $results = [];
        $index = 0;

        try {
            foreach ($ops as $op) {
                $index = $op->index;
                $results[] = $backend->executeOne($op, $lids);
            }
        } catch (JsonApiExceptionInterface $exception) {
            $backend->rollback();

            return $this->errorResponse($exception, $index);
        } catch (\Throwable $throwable) {
            $backend->rollback();

            throw $throwable;
        }

        try {
            $backend->commit();
        } catch (JsonApiExceptionInterface $exception) {
            $backend->rollback();

            return $this->errorResponse($exception, $index);
        } catch (\Throwable $throwable) {
            $backend->rollback();

            throw $throwable;
        }

        return AtomicResultsResponse::fromResults($results);
    }

    /**
     * Builds the rolled-back error response: the exception's errors prefixed with the
     * failing operation's index, on an {@see ErrorResponse} advertising the Atomic
     * Operations extension on its `Content-Type` (a document produced under an applied
     * extension must declare it).
     */
    private function errorResponse(JsonApiExceptionInterface $exception, int $index): ErrorResponse
    {
        return ErrorResponse::fromErrors(...$this->prefixErrors($exception, $index))
            ->withExtensions([AtomicExtension::URI]);
    }

    /**
     * Rebuilds the exception's errors with each `source.pointer` prefixed by the
     * failing operation's index, preserving every other error member.
     *
     * @return list<Error>
     */
    private function prefixErrors(JsonApiExceptionInterface $exception, int $index): array
    {
        $prefix = '/' . AtomicExtension::OPERATIONS_MEMBER . '/' . $index;

        $errors = [];
        foreach ($exception->getErrors() as $error) {
            $errors[] = $this->prefixError($error, $prefix);
        }

        return $errors;
    }

    /**
     * Prefixes one error's `source.pointer`: a present inner pointer is appended to
     * the operation prefix (`/atomic:operations/<i>` + `/data/attributes/title`); an
     * error with no source is located at the operation itself
     * (`/atomic:operations/<i>`). An inner source carrying a `parameter`/`header`
     * rather than a pointer is left **unchanged** — a JSON:API `source` should carry
     * only one of `pointer`/`parameter`/`header`, so the original single-member
     * source is preserved rather than gaining a pointer alongside it. All other error
     * members are carried through.
     */
    private function prefixError(Error $error, string $prefix): Error
    {
        $source = $error->source;

        if ($source === null) {
            // No source at all: locate the error at the failing operation.
            $newSource = new ErrorSource($prefix, '');
        } elseif ($source->pointer !== '') {
            // A pointer source: prefix it with the operation path.
            $newSource = new ErrorSource($prefix . $source->pointer, '');
        } else {
            // A parameter/header source: keep it as-is (single source member).
            $newSource = $source;
        }

        return new Error(
            id: $error->id,
            status: $error->status,
            code: $error->code,
            title: $error->title,
            detail: $error->detail,
            source: $newSource,
            links: $error->links,
            meta: $error->meta,
        );
    }
}
