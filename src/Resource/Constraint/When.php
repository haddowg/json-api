<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Conditional constraint: the wrapped constraints apply only when the
 * `$condition` closure returns true for the value under validation.
 *
 * **Not round-tripped to JSON Schema** — the {@see \haddowg\JsonApi\Validation\SchemaCompiler}
 * skips `When` (the condition is opaque PHP). Framework adapters that execute
 * validation evaluate the closure.
 */
final readonly class When implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    /**
     * @var list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public array $constraints;

    /**
     * The condition receives the value first and the request second (nullable —
     * `null` for a context that carries no request, e.g. entity-level or
     * filter-side validation), so an existing `fn($value)` closure keeps binding
     * unchanged. `When` is metadata only here (the bundle's constraint translator
     * is the sole execution site), so this is a documentation widening with no
     * runtime change.
     *
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface|null): bool $condition
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     */
    public function __construct(
        public \Closure $condition,
        array $constraints,
        public Context $context = new Context(),
    ) {
        $this->constraints = $constraints;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
