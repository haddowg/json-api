<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Atomic\OperationDescriptor;

/**
 * `POST /operations` — the JSON:API Atomic Operations extension batch: an ordered
 * list of write operations applied all-or-nothing within one request.
 *
 * Unlike the CRUD operations it carries no single primary resource: it wraps the
 * parsed {@see OperationDescriptor}s plus the shared {@see OperationContext} and
 * {@see QueryParameters}. Its {@see target()} is a synthetic sentinel — the
 * extension namespace as the `type`, no `id`, no `relationship` — constructed only
 * so the operation satisfies {@see JsonApiOperationInterface}; nothing in the
 * dispatch path ({@see \haddowg\JsonApi\Server\Server::fireServing()},
 * {@see \haddowg\JsonApi\Server\Server::dispatch()}'s strict query-parameter
 * validation) dereferences the target's type against the registry, so the sentinel
 * is inert. The per-operation targets the executor actually resolves live on the
 * {@see OperationDescriptor}s, reachable via {@see descriptors()}.
 */
final readonly class AtomicOperationsOperation implements JsonApiOperationInterface
{
    private Target $target;

    /**
     * @param list<OperationDescriptor> $descriptors
     */
    public function __construct(
        private array $descriptors,
        private QueryParameters $queryParameters,
        private OperationContext $context,
    ) {
        // A synthetic, inert target: the atomic batch has no single primary
        // resource, so the type is the extension namespace sentinel and there is
        // no id/relationship. It exists solely to satisfy the operation contract.
        $this->target = new Target(AtomicExtension::NAMESPACE);
    }

    public function target(): Target
    {
        return $this->target;
    }

    public function queryParameters(): QueryParameters
    {
        return $this->queryParameters;
    }

    public function context(): OperationContext
    {
        return $this->context;
    }

    /**
     * The parsed operations of the batch, in request order.
     *
     * @return list<OperationDescriptor>
     */
    public function descriptors(): array
    {
        return $this->descriptors;
    }
}
